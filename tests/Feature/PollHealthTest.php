<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DevicePollHealth;
use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Services\PollHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alarming when a poller silently stops reaching a device.
 *
 * On 6 October fourteen appliances had gone two to five days without one successful
 * SSH poll, behind a dashboard reading 304 of 304 reachable and zero unmeasured. ICMP
 * and SNMP answered, so each device "counted"; SSH had been refused since a hardware
 * swap changed the login, and the only trace was a Log::error per sweep.
 */
class PollHealthTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name = 'SC005-ECB01'): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'name' => $name,
            'role' => 'edgeconnect',
            'ip_address' => '10.200.5.254',
        ]);
    }

    private function alarm(Device $d, string $transport = 'ssh'): ?DeviceAlarm
    {
        return DeviceAlarm::where('device_id', $d->id)
            ->where('alarm_id', PollHealthMonitor::ALARM_PREFIX.$transport)
            ->first();
    }

    public function test_a_poller_that_stops_getting_in_raises_an_alarm(): void
    {
        $device = $this->device();
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(5)]);

        $out = (new PollHealthMonitor)->sweep();

        $this->assertSame(1, $out['opened']);
        $alarm = $this->alarm($device);
        $this->assertNotNull($alarm);
        $this->assertSame('warning', $alarm->severity);
        $this->assertStringContainsString('5 days', $alarm->description);
    }

    public function test_the_alarm_says_which_data_is_now_untrustworthy(): void
    {
        // The operator needs to know what the staleness costs, not merely that it
        // happened. SSH is where tunnel and next-hop state comes from.
        $device = $this->device();
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(3)]);

        (new PollHealthMonitor)->sweep();

        $this->assertStringContainsString('Tunnel state, next-hops', $this->alarm($device)->description);
    }

    public function test_a_slow_sweep_is_not_a_fault(): void
    {
        // A full SSH sweep of the Massey fleet measured 103 MINUTES on 6 October, so
        // a device sitting at 100 minutes is healthy and about to be polled. An
        // earlier threshold of 120 minutes left seventeen minutes of headroom over
        // that, which is nothing: sweep duration grows with the fleet, and grows
        // fastest when devices start failing, because each refused login costs a
        // full connect timeout. The condition this alarm detects is the condition
        // that would have tipped it into alarming on everything at once.
        $device = $this->device();
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subMinutes(103)]);

        $this->assertSame(0, (new PollHealthMonitor)->sweep()['opened']);
        $this->assertNull($this->alarm($device));
    }

    public function test_the_threshold_keeps_real_margin_over_a_full_sweep(): void
    {
        // Encoded so nobody tightens it back without re-measuring. 103 minutes is the
        // observed sweep; the genuinely-failing devices the same day were at a
        // MINIMUM of 1,027 minutes, so a generous margin costs no detection at all.
        $this->assertGreaterThanOrEqual(
            103 * 3,
            PollHealthMonitor::DEFAULT_STALE_MINUTES,
            'the staleness threshold must clear a full sweep several times over',
        );
        $this->assertLessThan(
            1027,
            PollHealthMonitor::DEFAULT_STALE_MINUTES,
            'and must still be well under how stale a genuinely broken device gets',
        );
    }

    public function test_recovery_clears_the_alarm(): void
    {
        $device = $this->device();
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(5)]);
        (new PollHealthMonitor)->sweep();
        $this->assertNotNull($this->alarm($device));

        PollHealthMonitor::success($device, 'ssh');
        $out = (new PollHealthMonitor)->sweep();

        $this->assertSame(1, $out['cleared']);
        $this->assertNotNull($this->alarm($device)->cleared_at);
    }

    public function test_a_device_nobody_polls_over_a_transport_never_alarms_for_it(): void
    {
        // The self-scoping property. A row exists only where a poller has actually
        // tried, so a FortiGate nobody SSHes to cannot raise an SSH staleness alarm.
        $device = $this->device('FL0001-HQ-FW');

        $this->assertSame(0, (new PollHealthMonitor)->sweep()['opened']);
        $this->assertSame(0, DevicePollHealth::count());
    }

    public function test_a_brand_new_device_is_new_not_stale(): void
    {
        // Never succeeded and only just created: that is not a fault yet, and
        // alarming on it would fire for every device the moment it is added.
        $device = $this->device();
        PollHealthMonitor::failure($device, 'ssh', 'SSH login failed — check the SSH username and password.');

        $this->assertSame(0, (new PollHealthMonitor)->sweep()['opened']);
    }

    public function test_a_device_that_has_never_once_answered_does_alarm_eventually(): void
    {
        // The other half: never succeeding is the worst case, not an exemption.
        $device = $this->device();
        PollHealthMonitor::failure($device, 'ssh', 'SSH login failed — check the SSH username and password.');
        DevicePollHealth::query()->update(['created_at' => now()->subDays(4)]);

        $this->assertSame(1, (new PollHealthMonitor)->sweep()['opened']);
    }

    public function test_the_last_error_is_carried_into_the_alarm(): void
    {
        $device = $this->device();
        PollHealthMonitor::failure($device, 'ssh', 'SSH login failed — check the SSH username and password.');
        DevicePollHealth::query()->update(['created_at' => now()->subDays(4)]);

        (new PollHealthMonitor)->sweep();

        $this->assertStringContainsString('SSH login failed', $this->alarm($device)->description);
    }

    public function test_a_device_under_maintenance_does_not_alarm(): void
    {
        $device = $this->device();
        MaintenanceWindow::create([
            'name' => 'Appliance swap',
            'device_id' => $device->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(5)]);

        $this->assertSame(0, (new PollHealthMonitor)->sweep()['opened']);
    }

    public function test_a_site_wide_window_covers_its_devices(): void
    {
        $device = $this->device();
        MaintenanceWindow::create([
            'name' => 'Site power work',
            'site_id' => $device->site_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(5)]);

        $this->assertSame(0, (new PollHealthMonitor)->sweep()['opened']);
    }

    public function test_an_expired_window_stops_suppressing(): void
    {
        $device = $this->device();
        MaintenanceWindow::create([
            'name' => 'Finished last week',
            'device_id' => $device->id,
            'starts_at' => now()->subDays(9),
            'ends_at' => now()->subDays(8),
        ]);
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(5)]);

        $this->assertSame(1, (new PollHealthMonitor)->sweep()['opened']);
    }

    public function test_a_success_clears_the_failure_streak(): void
    {
        $device = $this->device();
        PollHealthMonitor::failure($device, 'ssh', 'nope');
        PollHealthMonitor::failure($device, 'ssh', 'nope');
        $this->assertSame(2, DevicePollHealth::first()->consecutive_failures);

        PollHealthMonitor::success($device, 'ssh');

        $row = DevicePollHealth::first();
        $this->assertSame(0, $row->consecutive_failures);
        $this->assertNull($row->last_error);
    }

    public function test_the_open_alarm_keeps_its_age_current(): void
    {
        // A row frozen at the figure it had when it opened tells the NOC a device has
        // been stale for two hours when it has in fact been stale for a week.
        $device = $this->device();
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subHours(8)]);
        (new PollHealthMonitor)->sweep();
        $this->assertStringContainsString('8 hours', $this->alarm($device)->description);

        DevicePollHealth::query()->update(['last_success_at' => now()->subDays(6)]);
        (new PollHealthMonitor)->sweep();

        $this->assertStringContainsString('6 days', $this->alarm($device)->fresh()->description);
    }

    public function test_stale_minutes_diffs_forward(): void
    {
        // Carbon 3 returns a SIGNED difference; the naive form is negative and would
        // report every stale device as perfectly fresh. Shipped three times already.
        $device = $this->device();
        PollHealthMonitor::success($device, 'ssh');
        DevicePollHealth::query()->update(['last_success_at' => now()->subMinutes(500)]);

        $this->assertEqualsWithDelta(500, DevicePollHealth::first()->stale_minutes, 2);
    }

    public function test_transport_values_fit_the_column(): void
    {
        // SQLite ignores column widths and MySQL does not — the divergence that
        // 500'd v0.10.63 on production.
        foreach (DevicePollHealth::TRANSPORTS as $t) {
            $this->assertLessThanOrEqual(DevicePollHealth::TRANSPORT_MAX, strlen($t));
        }
    }
}
