<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A device under maintenance is being worked on, not ignored.
 *
 * Circuits have had this since maintenance was added: they carry their own
 * circuits_maintenance count and are excluded from alarming outright. Devices never
 * got the same treatment, so a switch put under maintenance for thirty days went on
 * presenting a red DOWN alarm and counting toward active_alarms exactly as before —
 * which is what an operator reads to decide whether anything needs attention.
 *
 * The alarm is NOT deleted and NOT downgraded to healthy. The device really is
 * unreachable, and pretending otherwise is the mistake this app keeps being dug out
 * of. It is marked, counted separately, and shown without the red.
 */
class DashboardMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function downDeviceAt(Site $site, string $name): Device
    {
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => $name]);
        DeviceAlarm::factory()->create([
            'device_id' => $device->id,
            'alarm_id' => 'device-unreachable',
            'severity' => 'critical',
            'cleared_at' => null,
        ]);

        return $device;
    }

    private function dashboard(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        return $this->getJson('/api/dashboard')->assertOk()->json();
    }

    public function test_an_alarm_on_a_device_in_maintenance_is_flagged(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDeviceAt($site, 'FL0002-SC891SWA003');
        MaintenanceWindow::create([
            'name' => 'Switch replacement', 'device_id' => $device->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(30),
        ]);

        $alert = collect($this->dashboard()['alerts'])->firstWhere('device_name', 'FL0002-SC891SWA003');

        $this->assertNotNull($alert, 'the alarm still shows — a device in maintenance is not healthy');
        $this->assertTrue($alert['in_maintenance'], 'and it says why it is expected');
    }

    public function test_it_does_not_count_toward_active_alarms(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDeviceAt($site, 'planned');
        MaintenanceWindow::create([
            'name' => 'Planned', 'device_id' => $device->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(30),
        ]);

        $counts = $this->dashboard()['counts'];

        $this->assertSame(0, $counts['active_alarms'], 'the number an operator scans must hold only what needs attention');
        $this->assertSame(1, $counts['alarms_maintenance']);
        $this->assertSame(1, $counts['devices_maintenance']);
    }

    public function test_an_unplanned_alarm_still_counts(): void
    {
        // The control. Without it this is just alarm suppression with extra steps.
        $site = Site::factory()->create();
        $this->downDeviceAt($site, 'unplanned');

        $counts = $this->dashboard()['counts'];

        $this->assertSame(1, $counts['active_alarms']);
        $this->assertSame(0, $counts['alarms_maintenance']);
    }

    public function test_a_site_window_covers_the_devices_at_that_site(): void
    {
        $site = Site::factory()->create();
        $this->downDeviceAt($site, 'at-the-site');
        MaintenanceWindow::create([
            'name' => 'Site cutover', 'site_id' => $site->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2),
        ]);

        $this->assertSame(0, $this->dashboard()['counts']['active_alarms']);
    }

    public function test_a_window_that_has_not_started_does_not_cover_anything(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDeviceAt($site, 'future-window');
        MaintenanceWindow::create([
            'name' => 'Next week', 'device_id' => $device->id,
            'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(8),
        ]);

        $this->assertSame(1, $this->dashboard()['counts']['active_alarms'], 'scheduled is not the same as active');
    }

    public function test_an_expired_window_stops_covering(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDeviceAt($site, 'expired-window');
        MaintenanceWindow::create([
            'name' => 'Finished', 'device_id' => $device->id,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(),
        ]);

        $this->assertSame(1, $this->dashboard()['counts']['active_alarms'], 'the alarm comes back when the window closes');
    }
}
