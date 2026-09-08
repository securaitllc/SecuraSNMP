<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\Site;
use App\Services\AnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_robust_z_and_sustained_detection(): void
    {
        $base = array_merge(array_fill(0, 20, 100.0), [98.0, 102.0, 101.0, 99.0]);

        $this->assertGreaterThan(50, AnomalyDetector::robustZ($base, 900.0));       // clear spike
        $this->assertLessThan(1, abs(AnomalyDetector::robustZ($base, 103.0)));      // within noise
        $this->assertNull(AnomalyDetector::robustZ([1, 2, 3], 99.0));               // too few to judge

        $this->assertSame('spike', AnomalyDetector::sustained($base, [880.0, 905.0, 890.0])['direction']);
        $this->assertNull(AnomalyDetector::sustained($base, [880.0, 101.0, 890.0])); // one normal → not sustained
        // drops don't count for spikes-only metrics (discards / latency)
        $this->assertNull(AnomalyDetector::sustained($base, [2.0, 1.0, 3.0], spikesOnly: true));

        // A near-zero baseline can't explode the z to ~1e15 — it is clamped to a sane 100
        // (a "from-zero" breach reads off-scale without the meaningless +1000σ).
        $flat = array_fill(0, 20, 0.0);
        $this->assertSame(100.0, AnomalyDetector::robustZ($flat, 2_500_000_000.0));
    }

    public function test_idle_interface_throughput_is_not_flagged(): void
    {
        // An interface idle for its whole baseline (near-zero octets) then carrying
        // traffic is NORMAL use, not an anomaly — this was the fleet-wide false-positive
        // (zero baseline → 1e15 z). No throughput anomaly should open.
        $if = DeviceInterface::factory()->create(['status' => 'up', 'speed_bps' => 1_000_000_000]);
        foreach (range(1, 22) as $i) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(25 - $i), 'status' => 'up',
                'in_octets_delta' => 500, 'out_octets_delta' => 500, 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        }
        foreach ([2_500_000_000, 2_400_000_000, 2_600_000_000] as $j => $v) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(3 - $j), 'status' => 'up',
                'in_octets_delta' => $v, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        }

        (new AnomalyDetector)->scanInterface($if->id);
        $this->assertSame(0, Anomaly::open()->where('metric', 'throughput')->count());
    }

    public function test_busy_baseline_throughput_spike_opens(): void
    {
        // A port carrying real sustained load (well above the idle floor) that then
        // spikes 10× IS a throughput anomaly. z is bounded, not 1e15.
        $if = DeviceInterface::factory()->create(['status' => 'up', 'speed_bps' => 1_000_000_000]);
        foreach (range(1, 22) as $i) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(25 - $i), 'status' => 'up',
                'in_octets_delta' => 300_000_000 + ($i % 3) * 1_000_000, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        }
        foreach ([3_000_000_000, 3_100_000_000, 2_950_000_000] as $j => $v) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(3 - $j), 'status' => 'up',
                'in_octets_delta' => $v, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        }

        (new AnomalyDetector)->scanInterface($if->id);
        $hit = Anomaly::open()->where('metric', 'throughput')->where('entity_id', $if->id)->first();
        $this->assertNotNull($hit);
        $this->assertLessThanOrEqual(1000.0, (float) $hit->z_score);
    }

    public function test_discards_are_scanned_even_when_speed_is_unknown(): void
    {
        // A port whose speed SNMP never reported (speed_bps 0) still discards packets;
        // the fleet scan must not skip it (it used to require speed_bps > 0).
        $if = DeviceInterface::factory()->create(['status' => 'up', 'speed_bps' => 0]);
        foreach (range(1, 20) as $i) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(25 - $i), 'status' => 'up',
                'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        }
        foreach ([80, 90, 85] as $j => $v) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(3 - $j), 'status' => 'up',
                'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => $v, 'out_discards_delta' => 0]);
        }

        (new AnomalyDetector)->scanInterfaces();

        $this->assertDatabaseHas('anomalies', ['entity_type' => 'interface', 'entity_id' => $if->id, 'metric' => 'discards', 'resolved_at' => null]);
    }

    public function test_interface_errors_are_scanned_as_an_anomaly(): void
    {
        // A sustained interface-error rate above the port's own (flat, ~0) baseline is a
        // bad-cable / bad-optic / duplex-mismatch signal — it must surface as an anomaly,
        // just like discards. Previously errors were recorded but never analyzed.
        $if = DeviceInterface::factory()->create(['status' => 'up', 'speed_bps' => 0]);
        foreach (range(1, 20) as $i) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(25 - $i), 'status' => 'up',
                'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0, 'in_errors_delta' => 0, 'out_errors_delta' => 0]);
        }
        foreach ([120, 140, 130] as $j => $v) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(3 - $j), 'status' => 'up',
                'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0, 'in_errors_delta' => $v, 'out_errors_delta' => 0]);
        }

        (new AnomalyDetector)->scanInterfaces();

        $this->assertDatabaseHas('anomalies', ['entity_type' => 'interface', 'entity_id' => $if->id, 'metric' => 'errors', 'resolved_at' => null]);
    }

    public function test_a_trivial_error_blip_is_ignored(): void
    {
        // A few CRC errors (below the floor) are noise, not an anomaly.
        $if = DeviceInterface::factory()->create(['status' => 'up', 'speed_bps' => 0]);
        foreach (range(1, 20) as $i) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(25 - $i), 'status' => 'up',
                'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0, 'in_errors_delta' => 0, 'out_errors_delta' => 0]);
        }
        foreach ([2, 3, 1] as $j => $v) {
            InterfaceMetricHistory::create(['device_interface_id' => $if->id, 'recorded_at' => now()->subMinutes(3 - $j), 'status' => 'up',
                'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0, 'in_errors_delta' => $v, 'out_errors_delta' => 0]);
        }

        (new AnomalyDetector)->scanInterfaces();

        $this->assertDatabaseMissing('anomalies', ['entity_type' => 'interface', 'entity_id' => $if->id, 'metric' => 'errors']);
    }

    public function test_resolve_stale_closes_impossible_and_orphaned_anomalies(): void
    {
        // Impossible z (pre-clamp artifact) → resolved immediately.
        $huge = Anomaly::create(['entity_type' => 'interface', 'entity_id' => 1, 'metric' => 'throughput', 'direction' => 'spike',
            'baseline' => 0, 'observed' => 9e9, 'z_score' => 6.4e12, 'detected_at' => now(), 'last_seen_at' => now()]);
        // Orphaned (entity left the scan set → untouched for >3 sweeps) → resolved.
        $orphan = Anomaly::create(['entity_type' => 'interface', 'entity_id' => 2, 'metric' => 'discards', 'direction' => 'spike',
            'baseline' => 0, 'observed' => 80, 'z_score' => 40, 'detected_at' => now()->subHours(2), 'last_seen_at' => now()->subHours(2)]);
        // Live + sane → left open.
        $live = Anomaly::create(['entity_type' => 'circuit', 'entity_id' => 3, 'metric' => 'latency', 'direction' => 'spike',
            'baseline' => 10, 'observed' => 120, 'z_score' => 25, 'detected_at' => now(), 'last_seen_at' => now()]);

        $n = (new AnomalyDetector)->resolveStale(1800); // 3 × 600s
        $this->assertSame(2, $n);
        $this->assertNotNull($huge->fresh()->resolved_at);
        $this->assertNotNull($orphan->fresh()->resolved_at);
        $this->assertNull($live->fresh()->resolved_at);
    }

    public function test_circuit_packet_loss_anomaly_opens(): void
    {
        // Loss normally 0; a sustained jump to ~8% is the drop signal the NOC watches.
        $circuit = Circuit::factory()->create(['site_id' => Site::factory()->create()->id, 'monitoring_enabled' => true]);
        foreach (range(1, 20) as $i) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(30 - $i), 'response_time_ms' => 10, 'loss_pct' => 0]);
        }
        foreach ([8.0, 9.0, 7.5] as $j => $v) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(3 - $j), 'response_time_ms' => 11, 'loss_pct' => $v]);
        }

        (new AnomalyDetector)->scanCircuit($circuit->id);
        $this->assertDatabaseHas('anomalies', ['entity_type' => 'circuit', 'entity_id' => $circuit->id, 'metric' => 'loss', 'resolved_at' => null]);

        // A sub-1% blip must NOT flag.
        $c2 = Circuit::factory()->create(['site_id' => Site::factory()->create()->id, 'monitoring_enabled' => true]);
        foreach (range(1, 20) as $i) {
            CircuitMetricHistory::create(['circuit_id' => $c2->id, 'recorded_at' => now()->subMinutes(30 - $i), 'response_time_ms' => 10, 'loss_pct' => 0]);
        }
        foreach ([0.3, 0.4, 0.2] as $j => $v) {
            CircuitMetricHistory::create(['circuit_id' => $c2->id, 'recorded_at' => now()->subMinutes(3 - $j), 'response_time_ms' => 10, 'loss_pct' => $v]);
        }
        (new AnomalyDetector)->scanCircuit($c2->id);
        $this->assertSame(0, Anomaly::open()->where('entity_id', $c2->id)->where('metric', 'loss')->count());
    }

    public function test_circuit_latency_anomaly_opens_then_resolves(): void
    {
        $circuit = Circuit::factory()->create(['site_id' => Site::factory()->create()->id, 'monitoring_enabled' => true]);

        // 20 baseline samples around 10 ms, then 3 sustained ~120 ms spikes.
        foreach (range(1, 20) as $i) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(100 - $i), 'response_time_ms' => 10 + ($i % 3), 'loss_pct' => 0]);
        }
        foreach ([120, 125, 122] as $j => $v) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(3 - $j), 'status' => 'up', 'response_time_ms' => $v, 'loss_pct' => 0]);
        }

        (new AnomalyDetector)->scanCircuit($circuit->id);
        $this->assertDatabaseHas('anomalies', ['entity_type' => 'circuit', 'entity_id' => $circuit->id, 'metric' => 'latency', 'resolved_at' => null]);

        // Latency back to normal → the open anomaly resolves.
        CircuitMetricHistory::where('circuit_id', $circuit->id)->delete();
        foreach (range(1, 25) as $i) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(30 - $i), 'response_time_ms' => 10, 'loss_pct' => 0]);
        }
        (new AnomalyDetector)->scanCircuit($circuit->id);
        $this->assertNotNull(Anomaly::first()->resolved_at);
        $this->assertSame(0, Anomaly::open()->count());
    }

    public function test_a_sustained_cpu_spike_opens_a_device_anomaly_above_the_floor(): void
    {
        // A device idling ~8% CPU that runs sustained-hot (above the 55% floor) is a real
        // device-health anomaly; a modest wobble below the floor is not.
        $device = \App\Models\Device::factory()->create();
        foreach (range(1, 22) as $i) {
            \App\Models\DeviceHealthHistory::create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes(60 - $i * 2),
                'cpu_pct' => 8 + ($i % 3), 'mem_pct' => 40, 'temperature_c' => 30]);
        }
        foreach ([88, 90, 86] as $j => $v) {
            \App\Models\DeviceHealthHistory::create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes(4 - $j),
                'cpu_pct' => $v, 'mem_pct' => 40, 'temperature_c' => 30]);
        }

        (new AnomalyDetector)->scanDevice($device->id);

        $cpu = Anomaly::open()->where('entity_type', 'device')->where('metric', 'cpu')->where('entity_id', $device->id)->first();
        $this->assertNotNull($cpu, 'a sustained hot-CPU device anomaly opens');
        $this->assertSame('spike', $cpu->direction);
        // A flat memory baseline that never breaches its floor stays quiet.
        $this->assertSame(0, Anomaly::open()->where('metric', 'memory')->count());
    }

    public function test_a_small_cpu_wobble_below_the_floor_is_not_flagged(): void
    {
        $device = \App\Models\Device::factory()->create();
        foreach (range(1, 22) as $i) {
            \App\Models\DeviceHealthHistory::create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes(60 - $i * 2),
                'cpu_pct' => 5, 'mem_pct' => 30, 'temperature_c' => 28]);
        }
        foreach ([18, 20, 19] as $j => $v) { // a spike vs baseline, but well under the 55% floor
            \App\Models\DeviceHealthHistory::create(['device_id' => $device->id, 'recorded_at' => now()->subMinutes(4 - $j),
                'cpu_pct' => $v, 'mem_pct' => 30, 'temperature_c' => 28]);
        }

        (new AnomalyDetector)->scanDevice($device->id);
        $this->assertSame(0, Anomaly::open()->where('metric', 'cpu')->count());
    }
}
