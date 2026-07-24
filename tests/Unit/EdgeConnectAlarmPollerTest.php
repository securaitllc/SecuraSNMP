<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\EdgeConnectAlarmPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeConnectAlarmPollerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWalker(array $responses): callable
    {
        return fn (Device $device, string $oid) => $responses[$oid] ?? '';
    }

    private function device(): Device
    {
        return Device::factory()->create(['vendor' => 'silverpeak', 'site_id' => Site::factory()->create()->id]);
    }

    /** One active alarm, exactly as the live appliance returns it. */
    private function liveAlarm(): array
    {
        return [
            '.1.3.6.1.2.1.1.3.0' => 'DISMAN-EVENT-MIB::sysUpTimeInstance = Timeticks: (12345) 0:02:03.45',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.4' => '.1.3.6.1.4.1.23867.3.1.1.2.1.1.4.1 = STRING: "tunnel_sw_ver_mismatch"',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.5' => '.1.3.6.1.4.1.23867.3.1.1.2.1.1.5.1 = STRING: "Many tunnels are connected to sites with different software version"',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6' => '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6.1 = STRING: "Tunnel"',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.9' => '.1.3.6.1.4.1.23867.3.1.1.2.1.1.9.1 = INTEGER: 1',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.13' => '.1.3.6.1.4.1.23867.3.1.1.2.1.1.13.1 = INTEGER: 65541',
        ];
    }

    public function test_active_alarm_is_recorded(): void
    {
        $device = $this->device();

        (new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm())))->poll($device);

        $this->assertDatabaseHas('device_alarms', [
            'device_id' => $device->id,
            'alarm_id' => 'ec:65541:Tunnel',
        ]);
        $alarm = DeviceAlarm::where('device_id', $device->id)->first();
        $this->assertStringContainsString('different software version', $alarm->description);
        $this->assertNull($alarm->cleared_at);
    }

    public function test_a_specific_tunnel_peer_is_named_in_the_description(): void
    {
        $device = $this->device();
        $walk = $this->liveAlarm();
        // A real per-tunnel alarm carries the peer/tunnel in the source column.
        $walk['.1.3.6.1.4.1.23867.3.1.1.2.1.1.6'] = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6.1 = STRING: "to_FL0084-SC49_DIA1-Broadband1"';

        (new EdgeConnectAlarmPoller($this->fakeWalker($walk)))->poll($device);

        $alarm = DeviceAlarm::where('device_id', $device->id)->first();
        $this->assertSame('ec:65541:to_FL0084-SC49_DIA1-Broadband1', $alarm->alarm_id);
        $this->assertStringContainsString('to_FL0084-SC49_DIA1-Broadband1', $alarm->description);
    }

    private function alarmRow(string $name, string $source, int $typeId): array
    {
        return [
            '.1.3.6.1.2.1.1.3.0' => 'sysUpTime = Timeticks: (99) 0:00:01',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.2' => '.2.1 = INTEGER: 3810',   // numeric severity code
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.4' => ".4.1 = STRING: \"{$name}\"",
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.5' => ".5.1 = STRING: \"{$name}\"",
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6' => ".6.1 = STRING: \"{$source}\"",
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.9' => '.9.1 = INTEGER: 1',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.13' => ".13.1 = INTEGER: {$typeId}",
        ];
    }

    public function test_a_wan_link_down_is_critical_not_warning(): void
    {
        $device = $this->device();
        (new EdgeConnectAlarmPoller($this->fakeWalker($this->alarmRow('equipment_if_link_down', 'wan0', 196617))))->poll($device);
        $this->assertSame('critical', DeviceAlarm::firstWhere('device_id', $device->id)->severity);
    }

    public function test_a_gateway_next_hop_unreachable_is_critical(): void
    {
        $device = $this->device();
        (new EdgeConnectAlarmPoller($this->fakeWalker($this->alarmRow('equipment_gateway_connect', 'gw:71.46.241.33', 196625))))->poll($device);
        $this->assertSame('critical', DeviceAlarm::firstWhere('device_id', $device->id)->severity);
    }

    public function test_alarm_no_longer_present_is_cleared_after_the_grace_poll(): void
    {
        $device = $this->device();
        DeviceAlarm::create([
            'device_id' => $device->id, 'alarm_id' => 'ec:65541:Tunnel',
            'description' => 'old', 'first_seen_at' => now(), 'cleared_at' => null,
            'active_on_device' => true,
        ]);

        // Reachable, alarm table empty. One missed poll is a GRACE — not cleared
        // (absorbs a transient/partial SNMP walk).
        $emptyTable = $this->fakeWalker(['.1.3.6.1.2.1.1.3.0' => 'sysUpTime = Timeticks: (99) 0:00:01']);
        (new EdgeConnectAlarmPoller($emptyTable))->poll($device);
        $this->assertNull(DeviceAlarm::where('device_id', $device->id)->first()->cleared_at, 'first miss should be grace, not cleared');

        // Second consecutive miss → genuinely gone → cleared.
        (new EdgeConnectAlarmPoller($emptyTable))->poll($device);
        $this->assertNotNull(DeviceAlarm::where('device_id', $device->id)->first()->cleared_at);
    }

    public function test_a_vanished_alarm_clears_immediately_when_others_remain(): void
    {
        $device = $this->device();
        // Poll 1: alarm A present.
        (new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm())))->poll($device);
        $this->assertNull(DeviceAlarm::firstWhere('device_id', $device->id)->cleared_at);

        // Poll 2: the walk clearly SUCCEEDED (returns a different alarm B), so the
        // now-absent alarm A is genuinely gone → cleared on THIS poll (no grace).
        $walk = $this->liveAlarm();
        $walk['.1.3.6.1.4.1.23867.3.1.1.2.1.1.6'] = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6.1 = STRING: "to_SOMEWHERE_DIA1-DIA1"';
        (new EdgeConnectAlarmPoller($this->fakeWalker($walk)))->poll($device);

        $a = DeviceAlarm::where('device_id', $device->id)->where('alarm_id', 'ec:65541:Tunnel')->first();
        $this->assertNotNull($a->cleared_at, 'a vanished alarm clears at once when the walk returned other alarms');
    }

    public function test_a_transient_missed_poll_does_not_flap_an_active_alarm(): void
    {
        $device = $this->device();
        // Poll 1: alarm present.
        (new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm())))->poll($device);
        $created = DeviceAlarm::where('device_id', $device->id)->first();
        $this->assertNull($created->cleared_at);
        $ticket = $created->ticket_number;

        // Poll 2: alarm-table walk comes back empty (a transient failure).
        (new EdgeConnectAlarmPoller($this->fakeWalker([
            '.1.3.6.1.2.1.1.3.0' => 'sysUpTime = Timeticks: (200) 0:00:02',
        ])))->poll($device);
        $this->assertNull(DeviceAlarm::where('device_id', $device->id)->first()->cleared_at, 'grace: not cleared');

        // Poll 3: alarm present again — same ticket, never flapped/reopened.
        (new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm())))->poll($device);
        $alarm = DeviceAlarm::where('device_id', $device->id)->first();
        $this->assertNull($alarm->cleared_at);
        $this->assertSame($ticket, $alarm->ticket_number, 'must not reopen with a new ticket');
        $this->assertSame(1, DeviceAlarm::where('device_id', $device->id)->count());
    }

    public function test_unreachable_device_does_not_clear_alarms(): void
    {
        $device = $this->device();
        DeviceAlarm::create([
            'device_id' => $device->id, 'alarm_id' => 'ec:65541:Tunnel',
            'description' => 'old', 'first_seen_at' => now(), 'cleared_at' => null,
        ]);

        // No sysUpTime reply => poll failed => nothing changes.
        (new EdgeConnectAlarmPoller($this->fakeWalker([])))->poll($device);

        $this->assertNull(DeviceAlarm::where('device_id', $device->id)->first()->cleared_at);
    }

    public function test_appliance_severity_maps_to_display_severity(): void
    {
        $device = $this->device();
        $walk = $this->liveAlarm();
        // A CRI tunnel_down alarm, exactly as the appliance reports it.
        $walk['.1.3.6.1.4.1.23867.3.1.1.2.1.1.2'] = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.2.1 = STRING: "CRI"';

        (new EdgeConnectAlarmPoller($this->fakeWalker($walk)))->poll($device);

        $this->assertSame('critical', DeviceAlarm::where('device_id', $device->id)->first()->severity);
    }

    public function test_tunnel_down_is_critical_when_severity_column_absent(): void
    {
        $device = $this->device();
        $walk = $this->liveAlarm();
        // No severity column; name signals a service-affecting tunnel outage.
        $walk['.1.3.6.1.4.1.23867.3.1.1.2.1.1.4'] = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.4.1 = STRING: "tunnel_down"';

        (new EdgeConnectAlarmPoller($this->fakeWalker($walk)))->poll($device);

        $this->assertSame('critical', DeviceAlarm::where('device_id', $device->id)->first()->severity);
    }

    public function test_ip_sla_alarm_stays_warning(): void
    {
        $device = $this->device();
        // No severity column, IP SLA down (WARN on the appliance) — must not
        // escalate to red just because the description contains "down".
        (new EdgeConnectAlarmPoller($this->fakeWalker([
            '.1.3.6.1.2.1.1.3.0' => 'sysUpTime = Timeticks: (99) 0:00:01',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.4' => '.4.1 = STRING: "IP SLA monitor down"',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6' => '.6.1 = STRING: "Ping for sp-ipsla on Port wan0"',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.9' => '.9.1 = INTEGER: 1',
            '.1.3.6.1.4.1.23867.3.1.1.2.1.1.13' => '.13.1 = INTEGER: 262189',
        ])))->poll($device);

        $this->assertSame('warning', DeviceAlarm::where('device_id', $device->id)->first()->severity);
    }

    public function test_new_alarm_gets_an_eight_digit_ticket(): void
    {
        $device = $this->device();

        (new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm())))->poll($device);

        $ticket = DeviceAlarm::where('device_id', $device->id)->first()->ticket_number;
        $this->assertMatchesRegularExpression('/^\d{8}$/', $ticket);
    }

    public function test_manually_cleared_alarm_is_not_resurrected_while_still_active(): void
    {
        $device = $this->device();
        // Alarm active; then a NOC clears it while the appliance still reports it.
        $poller = new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm()));
        $poller->poll($device);
        $alarm = DeviceAlarm::where('device_id', $device->id)->first();
        $ticket = $alarm->ticket_number;
        $alarm->update(['cleared_at' => now(), 'cleared_manually' => true]);

        // Next poll: appliance still reports it active — must stay cleared.
        $poller->poll($device);

        $alarm->refresh();
        $this->assertNotNull($alarm->cleared_at);
        $this->assertSame($ticket, $alarm->ticket_number);
    }

    public function test_cleared_alarm_reopens_with_new_ticket_after_a_flap(): void
    {
        $device = $this->device();
        $poller = new EdgeConnectAlarmPoller($this->fakeWalker($this->liveAlarm()));
        $poller->poll($device);
        $alarm = DeviceAlarm::where('device_id', $device->id)->first();
        $firstTicket = $alarm->ticket_number;
        $alarm->update(['cleared_at' => now(), 'cleared_manually' => true]);

        // Appliance clears the condition (alarm table empty) -> active_on_device false.
        (new EdgeConnectAlarmPoller($this->fakeWalker([
            '.1.3.6.1.2.1.1.3.0' => 'sysUpTime = Timeticks: (200) 0:00:02',
        ])))->poll($device);
        $this->assertFalse((bool) $alarm->fresh()->active_on_device);

        // Alarm returns (a flap) -> reopen with a fresh ticket.
        $poller->poll($device);

        $alarm->refresh();
        $this->assertNull($alarm->cleared_at);
        $this->assertNotSame($firstTicket, $alarm->ticket_number);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $alarm->ticket_number);
    }

    public function test_inactive_alarm_rows_are_ignored(): void
    {
        $device = $this->device();
        $walk = $this->liveAlarm();
        $walk['.1.3.6.1.4.1.23867.3.1.1.2.1.1.9'] = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.9.1 = INTEGER: 2'; // not active

        (new EdgeConnectAlarmPoller($this->fakeWalker($walk)))->poll($device);

        $this->assertDatabaseCount('device_alarms', 0);
    }
}
