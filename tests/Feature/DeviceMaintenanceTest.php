<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putting a device under maintenance.
 *
 * Windows already existed and suppressed notifications. What they never did was
 * change how the device reads or stop planned work counting against availability.
 */
class DeviceMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        return User::factory()->create(['role' => 'analyst']);
    }

    public function test_an_analyst_can_put_a_device_under_maintenance(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        $this->actingAs($this->analyst())
            ->postJson("/api/devices/{$device->id}/maintenance", ['hours' => 2, 'reason' => 'Switch firmware'])
            ->assertCreated()
            ->assertJsonPath('in_maintenance', true);

        $this->assertTrue($device->fresh()->inMaintenance());
    }

    public function test_it_defaults_to_a_window_that_expires_on_its_own(): void
    {
        // A forgotten window must not mute a device indefinitely.
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        $this->actingAs($this->analyst())->postJson("/api/devices/{$device->id}/maintenance")->assertCreated();

        $window = MaintenanceWindow::where('device_id', $device->id)->first();
        $this->assertTrue($window->ends_at->between(now()->addHours(3), now()->addHours(5)));
    }

    public function test_ending_maintenance_closes_the_window_rather_than_deleting_it(): void
    {
        // The window is the record of why the device was quiet; reporting reads it
        // afterwards, so it has to survive.
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        MaintenanceWindow::create([
            'name' => 'x', 'device_id' => $device->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3),
        ]);

        $this->actingAs($this->analyst())
            ->deleteJson("/api/devices/{$device->id}/maintenance")
            ->assertOk()
            ->assertJsonPath('in_maintenance', false);

        $this->assertSame(1, MaintenanceWindow::where('device_id', $device->id)->count());
        $this->assertFalse($device->fresh()->inMaintenance());
    }

    public function test_a_device_in_maintenance_is_not_reported_as_up(): void
    {
        // The false-healthy rule applies here too: maintenance is its own state, and a
        // device being worked on must never read as healthy.
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        MaintenanceWindow::create([
            'name' => 'x', 'device_id' => $device->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
        ]);

        $payload = $this->actingAs($this->analyst())->getJson("/api/devices/{$device->id}")->json('data');

        $this->assertTrue($payload['in_maintenance']);
        $this->assertNotNull($payload['maintenance_until']);
    }

    public function test_planned_downtime_does_not_count_against_availability(): void
    {
        // The point of the whole feature: a planned two-hour job must not read as an
        // SLA breach.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        DeviceAlarm::create([
            'device_id' => $device->id, 'alarm_id' => 'device-unreachable',
            'severity' => 'critical', 'description' => 'down',
            'first_seen_at' => now()->subDays(2), 'cleared_at' => now()->subDays(2)->addHours(2),
        ]);
        MaintenanceWindow::create([
            'name' => 'planned', 'device_id' => $device->id,
            'starts_at' => now()->subDays(2)->subMinutes(10),
            'ends_at' => now()->subDays(2)->addHours(3),
        ]);

        $report = (new ReportService)->generate('device-availability', now()->subDays(7), now(), []);
        $row = collect($report['rows'])->firstWhere('name', $device->name);

        $this->assertSame(100.0, $row['uptime_pct'], 'planned work is not an outage');
        $this->assertSame(0.0, $row['downtime_min']);
    }

    public function test_an_unplanned_outage_outside_the_window_still_counts(): void
    {
        // The exclusion must not become a blanket amnesty.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        DeviceAlarm::create([
            'device_id' => $device->id, 'alarm_id' => 'device-unreachable',
            'severity' => 'critical', 'description' => 'down',
            'first_seen_at' => now()->subDay(), 'cleared_at' => now()->subDay()->addHours(2),
        ]);
        MaintenanceWindow::create([
            'name' => 'unrelated', 'device_id' => $device->id,
            'starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHour(),
        ]);

        $report = (new ReportService)->generate('device-availability', now()->subDays(7), now(), []);
        $row = collect($report['rows'])->firstWhere('name', $device->name);

        $this->assertLessThan(100, $row['uptime_pct'], 'an unplanned outage still counts');
        $this->assertGreaterThan(100, $row['downtime_min']);
    }

    public function test_a_site_wide_window_covers_its_devices(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        MaintenanceWindow::create([
            'name' => 'site work', 'site_id' => $site->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
        ]);

        $this->assertTrue($device->fresh()->inMaintenance());
    }

    public function test_a_viewer_cannot_start_or_end_maintenance(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->postJson("/api/devices/{$device->id}/maintenance")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/devices/{$device->id}/maintenance")->assertForbidden();
    }

    public function test_maintenance_can_run_for_weeks(): void
    {
        // A switch replacement or a site build is not a four-hour job.
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        $this->actingAs($this->analyst())
            ->postJson("/api/devices/{$device->id}/maintenance", ['hours' => 336, 'reason' => 'Site rebuild'])
            ->assertCreated();

        $window = MaintenanceWindow::where('device_id', $device->id)->first();
        $this->assertTrue($window->ends_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertTrue($device->fresh()->inMaintenance());
    }

    public function test_an_explicit_end_date_is_accepted(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $ends = now()->addDays(45);

        $this->actingAs($this->analyst())
            ->postJson("/api/devices/{$device->id}/maintenance", ['ends_at' => $ends->toDateTimeString()])
            ->assertCreated();

        $this->assertSame(
            $ends->startOfMinute()->toDateTimeString(),
            MaintenanceWindow::where('device_id', $device->id)->first()->ends_at->startOfMinute()->toDateTimeString(),
        );
    }

    public function test_an_absurd_end_date_is_refused_so_a_typo_cannot_mute_a_device_forever(): void
    {
        // A mistyped year is the easiest way to silence a device indefinitely.
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        $this->actingAs($this->analyst())
            ->postJson("/api/devices/{$device->id}/maintenance", ['ends_at' => now()->addYears(3)->toDateTimeString()])
            ->assertStatus(422);

        $this->actingAs($this->analyst())
            ->postJson("/api/devices/{$device->id}/maintenance", ['hours' => 24 * 400])
            ->assertStatus(422);

        $this->assertFalse($device->fresh()->inMaintenance());
    }

    public function test_a_long_window_still_excludes_only_its_own_period(): void
    {
        // A three-week window must not retroactively forgive an outage before it.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        DeviceAlarm::create([
            'device_id' => $device->id, 'alarm_id' => 'device-unreachable',
            'severity' => 'critical', 'description' => 'down',
            'first_seen_at' => now()->subDays(6), 'cleared_at' => now()->subDays(6)->addHours(2),
        ]);
        MaintenanceWindow::create([
            'name' => 'rebuild', 'device_id' => $device->id,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->addDays(19),
        ]);

        $report = (new ReportService)->generate('device-availability', now()->subDays(7), now(), []);
        $row = collect($report['rows'])->firstWhere('name', $device->name);

        $this->assertLessThan(100, $row['uptime_pct'], 'the earlier outage is still counted');
    }
}
