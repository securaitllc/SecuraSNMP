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
 * The same answer on every screen.
 *
 * A switch was put under a thirty-day window and the dashboard KPI stopped counting
 * it — but the alarm list still listed it plainly, the bell still counted it, the map
 * still painted its site red, and the topology still drew it as an unexplained
 * outage. Each surface had answered "is this device under maintenance?" for itself,
 * or not at all.
 *
 * These tests pin the two halves of the rule that must hold everywhere:
 *   1. The alarm is STILL THERE and still says the device is down. A window does not
 *      make a dead switch healthy, and no surface may imply it does.
 *   2. It does not drive any "somebody needs to act" figure, and it says why.
 */
class MaintenanceEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private function downDevice(Site $site, string $name, string $role = 'switch'): Device
    {
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => $name, 'role' => $role]);
        DeviceAlarm::factory()->create([
            'device_id' => $device->id,
            'alarm_id' => 'device-unreachable',
            'severity' => 'critical',
            'cleared_at' => null,
        ]);

        return $device;
    }

    private function openWindow(Device $device): void
    {
        MaintenanceWindow::create([
            'name' => 'Switch replacement',
            'device_id' => $device->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    private function actAsNoc(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    public function test_the_alarm_list_marks_it_instead_of_hiding_it(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDevice($site, 'FL0002-SC891SWA003');
        $this->openWindow($device);
        $this->actAsNoc();

        $rows = $this->getJson('/api/alarms/log')->assertOk()->json('alarms');
        $row = collect($rows)->firstWhere('device_name', 'FL0002-SC891SWA003');

        $this->assertNotNull($row, 'the alarm stays in the log — a window is not a delete');
        $this->assertTrue($row['in_maintenance']);
    }

    public function test_an_alarm_outside_a_window_is_not_marked(): void
    {
        // The control. Without it the flag could be hard-coded true and pass above.
        $site = Site::factory()->create();
        $this->downDevice($site, 'unplanned-sw');
        $this->actAsNoc();

        $row = collect($this->getJson('/api/alarms/log')->assertOk()->json('alarms'))
            ->firstWhere('device_name', 'unplanned-sw');

        $this->assertFalse($row['in_maintenance']);
    }

    public function test_the_bell_does_not_count_it(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDevice($site, 'planned-sw');
        $this->openWindow($device);
        $this->actAsNoc();

        $counts = $this->getJson('/api/dashboard')->assertOk()->json('counts');

        $this->assertSame(0, $counts['active_incidents'], 'the bell badge is a call-somebody number');
        $this->assertSame(0, $counts['active_alerts']);
        $this->assertSame(1, $counts['alerts_maintenance'], 'counted apart, never silently dropped');
    }

    public function test_the_site_reads_maintenance_not_healthy_and_not_critical(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDevice($site, 'planned-sw');
        $this->openWindow($device);
        $this->actAsNoc();

        $row = collect($this->getJson('/api/dashboard')->assertOk()->json('sites'))
            ->firstWhere('id', $site->id);

        $this->assertSame('maintenance', $row['health'], 'green would claim the switch is fine; it is not');
        $this->assertSame(0, $row['active_alert_count']);
        $this->assertSame(1, $row['maintenance_alert_count']);
    }

    public function test_one_uncovered_device_keeps_the_site_critical(): void
    {
        $site = Site::factory()->create();
        $planned = $this->downDevice($site, 'planned-sw');
        $this->downDevice($site, 'unplanned-sw');
        $this->openWindow($planned);
        $this->actAsNoc();

        $row = collect($this->getJson('/api/dashboard')->assertOk()->json('sites'))
            ->firstWhere('id', $site->id);

        $this->assertSame('critical', $row['health'], 'a real outage beside planned work is still an outage');
        $this->assertSame(1, $row['active_alert_count']);
    }

    public function test_the_topology_node_is_marked_but_still_reads_down(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDevice($site, 'sc891-sw');
        $this->openWindow($device);
        $this->actAsNoc();

        $node = collect($this->getJson("/api/sites/{$site->id}/topology")->assertOk()->json('nodes'))
            ->firstWhere('id', "sw-{$device->id}");

        $this->assertSame('down', $node['status'], 'the box IS unreachable — the map must not say otherwise');
        $this->assertTrue($node['in_maintenance'], 'and it must say why nobody is being called');
    }

    public function test_the_org_grid_calls_a_fully_planned_site_maintenance(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDevice($site, 'sc891-sw');
        $this->openWindow($device);
        $this->actAsNoc();

        $card = collect($this->getJson('/api/topology')->assertOk()->json('sites'))
            ->firstWhere('id', $site->id);

        $this->assertSame('maint', $card['state']);
        $this->assertSame('Under maintenance', $card['summary']);
    }

    public function test_the_org_grid_keeps_a_partly_planned_site_red(): void
    {
        $site = Site::factory()->create();
        $planned = $this->downDevice($site, 'planned-sw');
        $this->downDevice($site, 'unplanned-sw');
        $this->openWindow($planned);
        $this->actAsNoc();

        $card = collect($this->getJson('/api/topology')->assertOk()->json('sites'))
            ->firstWhere('id', $site->id);

        $this->assertNotSame('maint', $card['state'], 'one uncovered device is still somebody\'s problem');
    }

    public function test_an_expired_window_puts_everything_back(): void
    {
        $site = Site::factory()->create();
        $device = $this->downDevice($site, 'was-planned');
        MaintenanceWindow::create([
            'name' => 'Finished', 'device_id' => $device->id,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(),
        ]);
        $this->actAsNoc();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->actAsNoc();
        $node = collect($this->getJson("/api/sites/{$site->id}/topology")->assertOk()->json('nodes'))
            ->firstWhere('id', "sw-{$device->id}");

        $this->assertSame(1, $dashboard['counts']['active_incidents'], 'the window closed — this is an outage again');
        $this->assertFalse($node['in_maintenance']);
    }
}
