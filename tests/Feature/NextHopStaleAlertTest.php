<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceNextHop;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\AlarmGroupingService;
use App\Services\NextHopPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * An open next-hop alert is a record that a gateway WAS down, not evidence it is.
 *
 * NextHopPoller closes one on recovery — but only for an appliance it reached.
 * `show system nexthops` returning nothing makes it return before the close, so an
 * appliance that stops answering SSH (rotated credentials, a replacement unit whose
 * output does not parse — an SD-WAN migration, in other words) holds its alerts open
 * for as long as SSH stays broken, and four sites read "next-hop unreachable" while
 * every one of them was up.
 */
class NextHopStaleAlertTest extends TestCase
{
    use RefreshDatabase;

    private function alertOn(string $hopStatus, ?int $checkedMinutesAgo): NextHopAlert
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'role' => 'edgeconnect']);
        $hop = DeviceNextHop::create([
            'device_id' => $device->id, 'ip_address' => '10.9.9.1', 'interface' => 'wan0',
            'status' => $hopStatus, 'reachability' => $hopStatus === 'down' ? 'unreachable' : 'reachable',
            'last_checked_at' => $checkedMinutesAgo === null ? null : now()->subMinutes($checkedMinutesAgo),
        ]);

        return NextHopAlert::factory()->create([
            'device_id' => $device->id, 'device_next_hop_id' => $hop->id,
            'started_at' => now()->subHours(3), 'ended_at' => null,
        ]);
    }

    public function test_an_alert_whose_gateway_is_up_again_is_not_an_incident(): void
    {
        $this->alertOn('up', 2);

        $this->assertSame(0, NextHopAlert::stillFailing()->count(), 'the poller missed the close; the gateway is up');
    }

    public function test_an_alert_the_poller_stopped_confirming_is_not_an_incident(): void
    {
        // The hop still SAYS down, but nothing has re-read it in hours — SSH to that
        // appliance is broken. We do not know, and a guess is not an incident: a site
        // that is genuinely dark raises device-down and SNMP WAN alarms, neither of
        // which depends on SSH.
        $this->alertOn('down', 240);

        $this->assertSame(0, NextHopAlert::stillFailing()->count());
    }

    public function test_a_gateway_that_is_actually_down_right_now_still_alarms(): void
    {
        $this->alertOn('down', 2);

        $this->assertSame(1, NextHopAlert::stillFailing()->count(), 'a real outage must survive every guard above it');
    }

    public function test_an_alert_orphaned_by_a_pruned_next_hop_is_not_an_incident(): void
    {
        $alert = $this->alertOn('down', 2);
        // The hop vanished from the appliance and was pruned; the foreign key is
        // nulled, so no device pass can ever match this alert again.
        DeviceNextHop::whereKey($alert->device_next_hop_id)->delete();

        $this->assertSame(0, NextHopAlert::stillFailing()->count());
    }

    public function test_the_dashboard_and_the_grouped_panel_both_apply_the_rule(): void
    {
        $this->alertOn('up', 2);
        Cache::flush();

        $alerts = $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->assertOk()->json('alerts');

        $this->assertEmpty(
            collect($alerts)->where('type', 'next_hop')->all(),
            'the bell counted these for as long as they sat open'
        );
        $this->assertSame([], (new AlarmGroupingService)->grouped());
    }

    public function test_the_sweep_closes_what_no_device_pass_can_reach(): void
    {
        $recovered = $this->alertOn('up', 2);
        $unconfirmed = $this->alertOn('down', 240);
        $real = $this->alertOn('down', 2);

        $closed = NextHopPoller::resolveStale();

        $this->assertSame(2, $closed);
        $this->assertNotNull($recovered->refresh()->ended_at);
        $this->assertNotNull($unconfirmed->refresh()->ended_at);
        $this->assertNull($real->refresh()->ended_at, 'the sweep closes leftovers; it must never touch a live outage');
    }

    public function test_the_sweep_never_opens_anything(): void
    {
        $this->alertOn('down', 2);

        NextHopPoller::resolveStale();

        $this->assertSame(1, NextHopAlert::whereNull('ended_at')->count());
    }
}
