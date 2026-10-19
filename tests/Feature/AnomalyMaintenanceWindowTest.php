<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceHealthHistory;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Services\AnomalyDetector;
use App\Support\Maintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A planned change is not an anomaly.
 *
 * Alarms already respect maintenance windows and availability reporting subtracts
 * them, but the baseline detector did not know about them at all — so every planned
 * cutover raised throughput and discard findings that an operator then had to
 * dismiss by hand. A watch-list that fills with expected noise stops being read.
 */
class AnomalyMaintenanceWindowTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Device $device;

    private function portWithASpike(): DeviceInterface
    {
        $this->site = Site::factory()->create();
        $this->device = Device::factory()->create(['site_id' => $this->site->id]);
        $if = DeviceInterface::factory()->create([
            'device_id' => $this->device->id, 'if_name' => 'ge-0/0/12',
            'status' => 'up', 'speed_bps' => 1_000_000_000,
        ]);

        $t = now()->subDays(3);
        for ($i = 0; $i < 60; $i++) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $if->id,
                'recorded_at' => $t->copy()->addMinutes($i * 5), 'status' => 'up',
                'in_octets_delta' => 1000, 'out_octets_delta' => 1000,
                'in_discards_delta' => 0, 'out_discards_delta' => 0,
                'in_errors_delta' => 0, 'out_errors_delta' => 0,
            ]);
        }
        for ($i = 60; $i < 70; $i++) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $if->id,
                'recorded_at' => $t->copy()->addMinutes($i * 5), 'status' => 'up',
                'in_octets_delta' => 1000, 'out_octets_delta' => 1000,
                'in_discards_delta' => 5000, 'out_discards_delta' => 5000,
                'in_errors_delta' => 0, 'out_errors_delta' => 0,
            ]);
        }

        return $if;
    }

    private function window(array $scope): void
    {
        MaintenanceWindow::create($scope + [
            'name' => 'Planned cutover',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
    }

    public function test_no_anomaly_is_raised_during_a_device_window(): void
    {
        $if = $this->portWithASpike();
        $this->window(['device_id' => $this->device->id]);

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertSame(0, Anomaly::where('entity_id', $if->id)->count());
    }

    public function test_a_site_window_covers_its_devices(): void
    {
        $if = $this->portWithASpike();
        $this->window(['site_id' => $this->site->id]);

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertSame(0, Anomaly::where('entity_id', $if->id)->count());
    }

    public function test_a_global_window_covers_the_fleet(): void
    {
        $if = $this->portWithASpike();
        $this->window([]);   // neither site nor device — the whole fleet

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertSame(0, Anomaly::where('entity_id', $if->id)->count());
    }

    public function test_the_same_spike_outside_any_window_still_raises(): void
    {
        // The control: without this the feature is just detection switched off.
        $if = $this->portWithASpike();

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertGreaterThan(0, Anomaly::where('entity_id', $if->id)->count());
    }

    public function test_a_window_elsewhere_does_not_silence_this_device(): void
    {
        $if = $this->portWithASpike();
        $other = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->window(['device_id' => $other->id]);

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertGreaterThan(0, Anomaly::where('entity_id', $if->id)->count());
    }

    public function test_a_finding_open_before_the_window_is_not_retired_by_it(): void
    {
        // Skipping is deliberate rather than reconciling to nothing: a real anomaly
        // raised before the cutover must survive it, not vanish because maintenance
        // started.
        $if = $this->portWithASpike();
        $this->window(['device_id' => $this->device->id]);
        Anomaly::create([
            'entity_type' => 'interface', 'entity_id' => $if->id, 'metric' => 'discards',
            'direction' => 'high', 'z_score' => 8.2, 'observed' => 4000, 'baseline' => 0,
            'detected_at' => now()->subDay(), 'last_seen_at' => now()->subHours(2),
        ]);

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertSame(1, Anomaly::open()->where('entity_id', $if->id)->count());
    }

    public function test_a_circuit_inside_a_window_is_skipped(): void
    {
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);
        MaintenanceWindow::create([
            'name' => 'ISP work', 'site_id' => $site->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);

        (new AnomalyDetector)->scanCircuit($circuit->id);

        $this->assertSame(0, Anomaly::where('entity_type', 'circuit')->where('entity_id', $circuit->id)->count());
    }

    /** A device whose CPU runs flat and then pegs — a device-scan finding. */
    private function deviceWithACpuSpike(): Device
    {
        $this->site = Site::factory()->create();
        $this->device = Device::factory()->create(['site_id' => $this->site->id]);

        $t = now()->subDays(3);
        for ($i = 0; $i < 60; $i++) {
            DeviceHealthHistory::create([
                'device_id' => $this->device->id,
                'recorded_at' => $t->copy()->addMinutes($i * 5),
                'cpu_pct' => 5.0, 'mem_pct' => 30.0, 'temperature_c' => 25.0,
            ]);
        }
        for ($i = 60; $i < 70; $i++) {
            DeviceHealthHistory::create([
                'device_id' => $this->device->id,
                'recorded_at' => $t->copy()->addMinutes($i * 5),
                'cpu_pct' => 95.0, 'mem_pct' => 30.0, 'temperature_c' => 25.0,
            ]);
        }

        return $this->device;
    }

    public function test_the_device_scan_also_respects_a_window(): void
    {
        // scanInterface and scanCircuit both skipped under maintenance; scanDevice did
        // not, so a planned reboot still raised cpu/memory/temperature findings — the
        // same hand-dismissed noise the window exists to prevent.
        $device = $this->deviceWithACpuSpike();
        $this->window(['device_id' => $device->id]);

        (new AnomalyDetector)->scanDevice($device->id);

        $this->assertSame(0, Anomaly::where('entity_type', 'device')->where('entity_id', $device->id)->count());
    }

    public function test_the_same_device_spike_outside_a_window_still_raises(): void
    {
        // The control: the guard above must not be detection switched off.
        $device = $this->deviceWithACpuSpike();

        (new AnomalyDetector)->scanDevice($device->id);

        $this->assertGreaterThan(0, Anomaly::where('entity_type', 'device')->where('entity_id', $device->id)->count());
    }

    public function test_a_device_discovered_inside_the_memo_ttl_is_still_under_a_fleet_window(): void
    {
        // covers() answered from a device list materialised when the memo was built, so
        // a device onboarded seconds later read as not-in-maintenance for up to the TTL
        // even though the window covers the whole fleet. coversSite() never agreed.
        $this->window([]);   // fleet-wide
        Maintenance::flush();
        $this->assertTrue(Maintenance::coversSite(null), 'memo now holds the fleet-wide flag');

        $late = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        $this->assertTrue(Maintenance::covers($late->id));
    }

    public function test_a_finding_open_before_the_window_survives_the_stale_sweep(): void
    {
        // The existing test asserts the window does not RETIRE a pre-window finding —
        // but it never ran the cleanup the poller runs first on every tick. Skipping the
        // scan stops last_seen_at advancing, so resolveStale aged the finding out about
        // half an hour into a thirty-day window. We stopped looking; it did not stop.
        $iface = $this->portWithASpike();
        (new AnomalyDetector)->scanInterface($iface->id);
        $open = Anomaly::open()->where('entity_type', 'interface')->where('entity_id', $iface->id)->firstOrFail();
        $open->update(['last_seen_at' => now()->subHours(2)]);

        $this->window(['device_id' => $iface->device_id]);
        // Past the Maintenance memo TTL, as the poller always is: it calls resolveStale
        // at the top of a tick that is minutes apart, never microseconds.
        $this->travel(31)->seconds();
        (new AnomalyDetector)->resolveStale(1800);

        $this->assertNull(
            $open->fresh()->resolved_at,
            'a finding on an entity nobody is scanning has not resolved — nobody looked',
        );
    }

    public function test_a_stale_finding_outside_a_window_is_still_retired(): void
    {
        // The control. Without it the guard above is just cleanup switched off.
        $iface = $this->portWithASpike();
        (new AnomalyDetector)->scanInterface($iface->id);
        $open = Anomaly::open()->where('entity_type', 'interface')->where('entity_id', $iface->id)->firstOrFail();
        $open->update(['last_seen_at' => now()->subHours(2)]);

        (new AnomalyDetector)->resolveStale(1800);

        $this->assertNotNull($open->fresh()->resolved_at);
    }
}
