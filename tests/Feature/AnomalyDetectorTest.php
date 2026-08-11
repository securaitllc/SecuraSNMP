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

        // A near-zero baseline can't explode the z to ~1e15 — it is clamped to MAX_Z.
        $flat = array_fill(0, 20, 0.0);
        $this->assertLessThanOrEqual(1000.0, AnomalyDetector::robustZ($flat, 2_500_000_000.0));
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
}
