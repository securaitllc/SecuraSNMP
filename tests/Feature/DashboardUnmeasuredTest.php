<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceMetricHistory;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Reachable" and "up" are claims, and a claim needs a measurement behind it.
 *
 * Both headline counts were total-minus-known-broken, so a device nobody had pinged
 * and a circuit nobody had probed counted as healthy. On 15 September the circuit
 * poller was being killed on every sweep and the fleet still read all-green until
 * the alarms caught up — the denominator said 304 of 304 the whole time.
 */
class DashboardUnmeasuredTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer']);
    }

    private function device(?string $polledAt): Device
    {
        $device = Device::factory()->for(Site::factory())->create();

        if ($polledAt !== null) {
            DeviceMetricHistory::create([
                'device_id' => $device->id,
                'recorded_at' => $polledAt,
                'response_time_ms' => 4.2,
            ]);
        }

        return $device;
    }

    public function test_a_device_nobody_has_polled_is_not_counted_as_reachable(): void
    {
        $this->device(now()->subMinutes(2));      // measured
        $this->device(now()->subHours(3));        // stale — poller stopped
        $this->device(null);                      // never polled at all

        $counts = $this->actingAs($this->viewer())->getJson('/api/dashboard')->json('counts');

        $this->assertSame(1, $counts['devices_reachable'], 'only the measured one may count');
        $this->assertSame(2, $counts['devices_unmeasured']);
    }

    public function test_a_circuit_not_probed_recently_is_not_counted_as_up(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->for($site)->create([
            'status' => 'up', 'monitoring_enabled' => true,
            'last_checked_at' => now()->subMinute(), 'last_measured_ok' => true,
        ]);
        Circuit::factory()->for($site)->create([
            'status' => 'up', 'monitoring_enabled' => true,
            'last_checked_at' => now()->subHours(2), 'last_measured_ok' => true,
        ]);

        $counts = $this->actingAs($this->viewer())->getJson('/api/dashboard')->json('counts');

        $this->assertSame(1, $counts['circuits_up']);
        $this->assertSame(1, $counts['circuits_unmeasured']);
    }

    public function test_a_circuit_the_sweep_could_not_measure_is_not_counted_as_up(): void
    {
        // The 15 September shape exactly: the ping never completed, so the verdict is
        // unknown. It kept its last status — which must not then be read as a reading.
        $site = Site::factory()->create();
        Circuit::factory()->for($site)->create([
            'status' => 'up', 'monitoring_enabled' => true,
            'last_checked_at' => now(), 'last_measured_ok' => false,
        ]);

        $counts = $this->actingAs($this->viewer())->getJson('/api/dashboard')->json('counts');

        $this->assertSame(0, $counts['circuits_up']);
        $this->assertSame(1, $counts['circuits_unmeasured']);
    }

    public function test_a_paused_circuit_is_not_reported_as_unmeasured(): void
    {
        // Monitoring deliberately off is not a blind spot — it has its own count, and
        // padding the unmeasured figure with it would make the warning meaningless.
        Circuit::factory()->for(Site::factory())->create([
            'status' => 'up', 'monitoring_enabled' => false, 'last_checked_at' => null,
        ]);

        $counts = $this->actingAs($this->viewer())->getJson('/api/dashboard')->json('counts');

        $this->assertSame(0, $counts['circuits_unmeasured']);
        $this->assertSame(1, $counts['circuits_maintenance']);
    }

    public function test_a_healthy_measured_fleet_still_reads_healthy(): void
    {
        // The guard must not make a working fleet look broken.
        $this->device(now()->subMinute());
        $this->device(now()->subMinute());
        Circuit::factory()->for(Site::factory())->create([
            'status' => 'up', 'monitoring_enabled' => true,
            'last_checked_at' => now(), 'last_measured_ok' => true,
        ]);

        $counts = $this->actingAs($this->viewer())->getJson('/api/dashboard')->json('counts');

        $this->assertSame(2, $counts['devices_reachable']);
        $this->assertSame(0, $counts['devices_unmeasured']);
        $this->assertSame(1, $counts['circuits_up']);
        $this->assertSame(0, $counts['circuits_unmeasured']);
    }
}
