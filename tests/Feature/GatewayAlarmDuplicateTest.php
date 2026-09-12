<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\AlarmGroupingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One WAN uplink failing is one alarm.
 *
 * #075 showed wan0 twice: "Gateway unreachable" at critical from the appliance's SNMP
 * alarm, and "Next-hop unreachable" at warning from the SSH `show system nexthops`
 * sweep. Same interface, same gateway IP, same fault — two rows, two severities, and
 * an operator left to work out that they were the same thing.
 *
 * The two paths disagree on severity by construction: SNMP reports what the appliance
 * says, while the SSH row computes critical-or-warning from whether another WAN is
 * still up. Neither is wrong; there should just be one of them, and the house rule
 * already says which — SNMP is authoritative, SSH confirms and adds detail.
 */
class GatewayAlarmDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Device $edge;

    private DeviceNextHop $wan0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'SC0075']);
        $this->edge = Device::factory()->create([
            'site_id' => $this->site->id, 'role' => 'edgeconnect', 'name' => 'sc075-sdw',
        ]);
        $this->wan0 = DeviceNextHop::create([
            'device_id' => $this->edge->id, 'ip_address' => '173.8.45.6', 'interface' => 'wan0',
            'status' => 'down', 'last_checked_at' => now(),
        ]);
        // A second uplink still up — this is what makes the SSH row compute "warning"
        // while the appliance's own alarm says critical.
        DeviceNextHop::create([
            'device_id' => $this->edge->id, 'ip_address' => '66.44.12.1', 'interface' => 'wan1',
            'status' => 'up', 'last_checked_at' => now(),
        ]);
    }

    private function snmpGatewayAlarm(string $ip = '173.8.45.6'): DeviceAlarm
    {
        return DeviceAlarm::factory()->create([
            'device_id' => $this->edge->id,
            'alarm_id' => "ec:196625:gw:{$ip}",
            'description' => 'Next hop is unreachable',
            'severity' => 'critical',
            'cleared_at' => null,
        ]);
    }

    private function sshNextHopAlert(): NextHopAlert
    {
        return NextHopAlert::create([
            'device_id' => $this->edge->id,
            'device_next_hop_id' => $this->wan0->id,
            'started_at' => now()->subMinutes(10),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function alerts(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        return $this->getJson('/api/dashboard')->assertOk()->json('alerts');
    }

    public function test_one_gateway_failure_is_one_alert(): void
    {
        $this->snmpGatewayAlarm();
        $this->sshNextHopAlert();

        $alerts = collect($this->alerts());
        // The pair may be correlated into one device incident; flatten so the test
        // counts SIGNALS, which is what was duplicated.
        $signals = $alerts->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);
        $aboutWan0 = $signals->filter(fn ($a) => in_array($a['type'], ['alarm', 'next_hop'], true));

        $this->assertCount(1, $aboutWan0, 'wan0 went down once, so it must be listed once');
        $this->assertSame('alarm', $aboutWan0->first()['type'], 'SNMP is the authoritative signal — its row is the one that stays');
    }

    public function test_the_surviving_row_names_the_interface(): void
    {
        // The SSH row was the only thing that knew "wan0"; SNMP names an IP. Losing it
        // would trade a duplicate for a worse single row.
        $this->snmpGatewayAlarm();
        $this->sshNextHopAlert();

        $signals = collect($this->alerts())
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);
        $row = $signals->firstWhere('type', 'alarm');

        $this->assertStringContainsString('(wan0)', $row['detail']);
    }

    public function test_an_ssh_next_hop_with_no_snmp_alarm_still_shows(): void
    {
        // The control. SSH is the only signal when SNMP has not raised — dropping it
        // would be the false-healthy failure, not a de-duplication.
        $this->sshNextHopAlert();

        $signals = collect($this->alerts())
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);

        $this->assertNotNull($signals->firstWhere('type', 'next_hop'), 'a gateway down only SSH can see is still an incident');
    }

    public function test_a_gateway_alarm_for_a_different_uplink_does_not_swallow_wan0(): void
    {
        // wan1's gateway alarmed; wan0's SSH alert is a separate fault and must survive.
        $this->snmpGatewayAlarm('66.44.12.1');
        $this->sshNextHopAlert();

        $signals = collect($this->alerts())
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);

        $this->assertNotNull($signals->firstWhere('type', 'next_hop'), 'two uplinks down is two faults');
    }

    public function test_an_ip_sla_alarm_does_not_swallow_the_next_hop(): void
    {
        // A reachable next-hop with a dead path to the internet is a DIFFERENT fault
        // from the next-hop not answering at all. Folding them hides one behind the other.
        DeviceAlarm::factory()->create([
            'device_id' => $this->edge->id,
            'alarm_id' => 'ec:196610:wan0',
            'description' => 'IP SLA down on wan0',
            'severity' => 'warning',
            'cleared_at' => null,
        ]);
        $this->sshNextHopAlert();

        $signals = collect($this->alerts())
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);

        $this->assertNotNull($signals->firstWhere('type', 'next_hop'));
    }

    public function test_the_by_isp_view_does_not_list_it_twice_either(): void
    {
        Circuit::factory()->create([
            'site_id' => $this->site->id, 'isp_name' => 'AT&T', 'circuit_id' => 'CKT-075',
            'gateway_ip' => '173.8.45.6', 'wan_interface' => 'wan0',
        ]);
        $this->snmpGatewayAlarm();
        $this->sshNextHopAlert();

        $rows = collect((new AlarmGroupingService)->grouped())
            ->flatMap(fn ($site) => collect($site['groups'])->flatMap(fn ($g) => $g['alarms']));

        $this->assertCount(1, $rows->filter(fn ($r) => str_contains(strtolower((string) $r['description']), 'unreachable')),
            'the ISP panel showed the same uplink twice under one circuit');
    }

    public function test_the_surviving_row_keeps_the_severity_of_the_row_it_replaced(): void
    {
        // The SSH row was the only signal that knew whether ANY uplink was left: it
        // graded itself critical when every next-hop was down. The appliance grades one
        // gateway and often calls it minor, so de-duplicating without carrying that
        // verdict turns "this site has lost every WAN" into a warning — the false-healthy
        // failure again, this time in the severity column.
        DeviceNextHop::where('device_id', $this->edge->id)->update(['status' => 'down']);
        DeviceAlarm::factory()->create([
            'device_id' => $this->edge->id,
            'alarm_id' => 'ec:196625:gw:173.8.45.6',
            'description' => 'Next hop is unreachable',
            'severity' => 'minor',
            'cleared_at' => null,
        ]);
        $this->sshNextHopAlert();

        $signals = collect($this->alerts())
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);

        $this->assertSame('critical', $signals->firstWhere('type', 'alarm')['severity']);
    }

    public function test_a_gateway_still_grades_by_its_own_severity_when_another_uplink_is_up(): void
    {
        // The control: wan1 is up, so the SSH row would have said warning. A minor
        // appliance alarm must not be inflated into a critical.
        DeviceAlarm::factory()->create([
            'device_id' => $this->edge->id,
            'alarm_id' => 'ec:196625:gw:173.8.45.6',
            'description' => 'Next hop is unreachable',
            'severity' => 'minor',
            'cleared_at' => null,
        ]);
        $this->sshNextHopAlert();

        $signals = collect($this->alerts())
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a]);

        $this->assertSame('warning', $signals->firstWhere('type', 'alarm')['severity']);
    }

    public function test_the_by_isp_group_still_reads_down(): void
    {
        // The rejected SSH row was what forced the circuit group to "down"; the device
        // alarm branch only calls it down when the text matches "link down on wanN",
        // which a gateway alarm never says. Without that, de-duplicating downgraded a
        // circuit whose gateway is gone to "degraded".
        Circuit::factory()->create([
            'site_id' => $this->site->id, 'isp_name' => 'AT&T', 'circuit_id' => 'CKT-075',
            'gateway_ip' => '173.8.45.6', 'wan_interface' => 'wan0', 'status' => 'up',
        ]);
        $this->snmpGatewayAlarm();
        $this->sshNextHopAlert();

        $groups = collect((new AlarmGroupingService)->grouped())
            ->flatMap(fn ($site) => $site['groups'])
            ->filter(fn ($g) => ($g['kind'] ?? '') === 'circuit');

        $this->assertSame(['down'], $groups->pluck('state')->all());
    }
}
