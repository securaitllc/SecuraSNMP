<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function ispOutageSite(): Site
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down', 'isp_name' => 'AT&T', 'circuit_id' => 'CKT-JAX-9032']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'next_hop_ip' => '99.12.40.9', 'name' => 'jax-ec01']);
        Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'down']);
        NextHopAlert::factory()->create(['device_id' => $edge->id, 'ended_at' => null]);
        Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'jax-sw01']);

        return $site;
    }

    public function test_isp_outage_correlates_to_one_incident_with_the_circuit_as_root(): void
    {
        $site = $this->ispOutageSite();
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");

        $res->assertOk();
        $res->assertJsonPath('incident.active', true);
        $res->assertJsonPath('incident.root_type', 'circuit');

        $body = $res->json();
        // Root cause is the ISP circuit; next-hop + tunnels are named symptoms.
        $this->assertStringContainsString('ISP outage', $body['incident']['summary']);
        $symptoms = implode(' ', $body['incident']['symptoms']);
        $this->assertStringContainsString('SD-WAN tunnel', $symptoms);
        $this->assertStringContainsString('next-hop', $symptoms);

        // The circuit → gateway link is flagged as the root-cause edge.
        $rootEdges = array_filter($body['edges'], fn ($e) => $e['root'] ?? false);
        $this->assertCount(1, $rootEdges);

        // Node states: cloud down, next-hop down, edge degraded, switch up.
        $byType = collect($body['nodes'])->keyBy('type');
        $this->assertSame('down', $byType['cloud']['status']);
        $this->assertSame('down', $byType['nexthop']['status']);
        $this->assertSame('warn', $byType['edge']['status']);
        $this->assertSame('up', $byType['switch']['status']);
    }

    public function test_wan_nexthop_down_with_circuit_up_advises_cable_interface_then_isp_ticket(): void
    {
        // Circuit still pings up (SD-WAN failover), but a WAN next-hop is
        // unreachable — the local access link, not the appliance.
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'hub-lab-sdw']);
        \App\Models\DeviceNextHop::create([
            'device_id' => $edge->id, 'ip_address' => '71.46.241.33', 'interface' => 'wan0',
            'reachability' => 'unreachable', 'status' => 'down', 'last_checked_at' => now(),
        ]);
        NextHopAlert::factory()->create(['device_id' => $edge->id, 'ended_at' => null]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");

        $res->assertOk();
        $res->assertJsonPath('incident.active', true);
        $res->assertJsonPath('incident.root_type', 'access');
        $body = $res->json();
        $this->assertStringContainsString('WAN uplink down', $body['incident']['summary']);
        $this->assertStringContainsString('71.46.241.33', $body['incident']['summary']);
        // Action: check cable/interface first, then ISP ticket for the modem/fiber.
        $this->assertStringContainsString('cable', $body['incident']['action']);
        $this->assertStringContainsString('ISP ticket', $body['incident']['action']);
    }

    public function test_stale_ssh_nexthop_alert_clears_when_the_snmp_wan_alarm_already_recovered(): void
    {
        // A WAN went down (SNMP gw alarm + SSH next-hop alert both opened), then
        // recovered: the fast 90s SNMP loop cleared its gw alarm, but the slow SSH
        // sweep hasn't closed its NextHopAlert yet. The site must read HEALTHY —
        // the SSH straggler must not keep it critical for minutes.
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'hqlab-sdw', 'status' => 'active']);
        \App\Models\DeviceNextHop::create([
            'device_id' => $edge->id, 'ip_address' => '71.46.241.33', 'interface' => 'wan0',
            'reachability' => 'unreachable', 'status' => 'down', 'last_checked_at' => now()->subMinutes(4),
        ]);
        NextHopAlert::factory()->create(['device_id' => $edge->id, 'started_at' => now()->subMinutes(20), 'ended_at' => null]);
        // The gw SNMP alarm that co-fired, now cleared AFTER the SSH alert opened.
        DeviceAlarm::factory()->create([
            'device_id' => $edge->id, 'alarm_id' => 'ec:5:gw:71.46.241.33',
            'first_seen_at' => now()->subMinutes(20), 'cleared_at' => now()->subMinutes(2),
        ]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->assertOk();
        $res->assertJsonPath('incident.active', false);
        // The next-hop pill reads up, not down.
        $nexthop = collect($res->json('nodes'))->firstWhere('type', 'nexthop');
        $this->assertSame('up', $nexthop['status']);
    }

    public function test_one_circuit_down_with_failover_working_is_degraded_not_an_outage(): void
    {
        // 1 of 2 WAN circuits down, but the SD-WAN still has up tunnels (failover).
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down', 'isp_name' => 'AT&T', 'circuit_id' => 'CKT-1']);
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up', 'isp_name' => 'Lumen', 'circuit_id' => 'CKT-2']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'up']);   // still passing traffic
        \App\Models\DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '1.1.1.1', 'interface' => 'wan0', 'reachability' => 'unreachable', 'status' => 'down', 'last_checked_at' => now()]);
        NextHopAlert::factory()->create(['device_id' => $edge->id, 'ended_at' => null]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");
        // A WAN down is CRITICAL even with failover (no redundancy left), but the
        // summary makes clear traffic still flows on the backup.
        $res->assertOk()->assertJsonPath('incident.root_type', 'degraded')->assertJsonPath('incident.severity', 'critical');
        $this->assertStringContainsString('backup WAN', $res->json('incident.summary'));
    }

    public function test_all_circuits_down_reads_as_site_isolated(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down', 'isp_name' => 'AT&T', 'circuit_id' => 'C1']);
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down', 'isp_name' => 'Lumen', 'circuit_id' => 'C2']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'down']);   // no working path
        NextHopAlert::factory()->create(['device_id' => $edge->id, 'ended_at' => null]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");
        $res->assertOk()->assertJsonPath('incident.root_type', 'circuit')->assertJsonPath('incident.severity', 'critical');
        $this->assertStringContainsString('Site isolated', $res->json('incident.summary'));
    }

    public function test_ip_sla_down_on_a_wan_is_a_degraded_incident_even_though_the_next_hop_is_reachable(): void
    {
        // wan0's gateway ARPs fine ("reachable") but the appliance's IP-SLA (ping
        // 8.8.8.8 out wan0) is DOWN — the WAN can't pass traffic. wan1 is fine, so
        // the site is degraded (running on backup), not a full outage.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sc186-sdw']);
        \App\Models\DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '64.203.210.1', 'interface' => 'wan0', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        \App\Models\DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '4.96.80.1', 'interface' => 'wan1', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'up']); // still passing traffic on wan1
        DeviceAlarm::factory()->create([
            'device_id' => $edge->id,
            'alarm_id' => 'ec:262189:Ping for sp-ipsla.silverpeak.cloud,8.8.8.8,8.8.4.4 on Port wan0 tunnel N/A label Broadband1',
            'severity' => 'warning', 'first_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->assertOk();
        $res->assertJsonPath('incident.active', true);
        $res->assertJsonPath('incident.root_type', 'degraded');
        $res->assertJsonPath('incident.severity', 'critical');
        $this->assertStringContainsString('WAN0', $res->json('incident.summary'));
        $this->assertStringContainsString('backup WAN', $res->json('incident.summary'));
        // The wan0 next-hop pill reads down; wan1 stays up.
        $nodes = collect($res->json('nodes'));
        $this->assertSame('down', $nodes->firstWhere('ip', '64.203.210.1')['status']);
        $this->assertSame('up', $nodes->firstWhere('ip', '4.96.80.1')['status']);
    }

    public function test_snmp_tunnel_alarm_surfaces_even_when_the_ssh_table_shows_all_up(): void
    {
        // SNMP is the TOP signal: a live ec:65537:Tunnel alarm means the site has a
        // tunnel problem NOW, even though the (slow, possibly hours-stale) SSH
        // per-tunnel table still shows every tunnel up. Previously this read
        // "Healthy" and hid the alarm.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        foreach (range(1, 3) as $i) {
            Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'up', 'hub' => 'HQ', 'last_checked_at' => now()->subHours(3)]);
        }
        \App\Models\DeviceAlarm::create(['device_id' => $edge->id, 'alarm_id' => 'ec:65537:Tunnel', 'severity' => 'critical', 'description' => 'tunnels down', 'first_seen_at' => now()]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");
        $res->assertOk();
        // Site flags the overlay problem from the SNMP alarm...
        $res->assertJsonPath('incident.active', true);
        $res->assertJsonPath('incident.root_type', 'edge');
        // ...and the edge node labels it honestly (SSH shows none down, so no fake
        // "1/3 down" fraction — it's the appliance's own alarm).
        $edgeNode = collect($res->json('nodes'))->firstWhere('type', 'edge');
        $this->assertSame('tunnels down (SNMP alarm)', $edgeNode['tunnels']);
    }

    public function test_a_down_switch_with_no_wan_fault_is_the_incident(): void
    {
        $site = Site::factory()->create();
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'acc-sw07']);
        \App\Models\DeviceAlarm::create(['device_id' => $sw->id, 'alarm_id' => 'device-unreachable', 'severity' => 'critical', 'description' => 'down', 'first_seen_at' => now()]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");
        $res->assertOk()->assertJsonPath('incident.root_type', 'switch');
        $this->assertStringContainsString('acc-sw07', $res->json('incident.summary'));
    }

    public function test_a_wan_alarm_alone_surfaces_the_wan_uplink_as_root_not_the_overlay(): void
    {
        // wan0 shut down: the appliance raises the gateway/next-hop alarm over SNMP,
        // but the SSH next-hop poller hasn't flagged it yet. The topology must still
        // call the WAN uplink the root cause (not "overlay degraded"), and name the
        // gateway from the alarm — otherwise troubleshooting is misled to the tunnels.
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'hq-lab-sdw']);
        \App\Models\DeviceAlarm::create([
            'device_id' => $edge->id, 'alarm_id' => 'ec:196625:gw:71.46.241.33',
            'severity' => 'critical', 'description' => 'Next-hop unreachable — gw:71.46.241.33',
            'first_seen_at' => now(),
        ]);
        // A downstream tunnel alarm exists too — it must NOT win over the WAN cause.
        \App\Models\DeviceAlarm::create([
            'device_id' => $edge->id, 'alarm_id' => 'ec:65537:Tunnel',
            'severity' => 'critical', 'description' => 'Many tunnels down', 'first_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");

        $res->assertOk()->assertJsonPath('incident.root_type', 'access');
        $body = $res->json();
        $this->assertStringContainsString('WAN uplink down', $body['incident']['summary']);
        $this->assertStringContainsString('71.46.241.33', $body['incident']['summary']);
    }

    public function test_ha_pair_collapses_to_one_node_and_holds_redundancy(): void
    {
        // Two firewalls in the same HA group render as ONE node; one member down
        // while its peer is up is degraded (warn), not a full outage.
        $site = Site::factory()->create();
        $active = Device::factory()->create(['site_id' => $site->id, 'role' => 'firewall', 'name' => 'CORP-FW-A', 'ha_group' => 'corp-fw', 'ha_role' => 'active', 'status' => 'active']);
        $standby = Device::factory()->create(['site_id' => $site->id, 'role' => 'firewall', 'name' => 'CORP-FW-B', 'ha_group' => 'corp-fw', 'ha_role' => 'standby', 'status' => 'inactive']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");

        $res->assertOk();
        $fw = collect($res->json('nodes'))->where('type', 'fw');
        $this->assertCount(1, $fw, 'HA firewalls should collapse to a single node');
        $node = $fw->first();
        $this->assertTrue($node['ha']);
        $this->assertSame('warn', $node['status']); // one up, one down → redundancy holds
        $this->assertCount(2, $node['ha_members']);
        $this->assertStringContainsString('CORP-FW-A', $node['label']);
        $this->assertStringContainsString('CORP-FW-B', $node['label']);
    }

    public function test_a_switch_with_lldp_uplink_is_not_also_wired_to_the_sdwan(): void
    {
        // Multi-switch site: sw2 uplinks to sw1 per LLDP (not the SD-WAN). The
        // topology must NOT also draw an inferred edge→sw2 link.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        $sw1 = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw1']);
        $sw2 = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw2']);
        // sw2 ↔ sw1 discovered via LLDP.
        \App\Models\LldpNeighbor::create(['device_id' => $sw2->id, 'local_port' => 'ge-0/0/48', 'remote_sysname' => 'sw1', 'remote_port' => 'ge-0/0/47', 'remote_device_id' => $sw1->id, 'last_seen_at' => now()]);
        $user = User::factory()->create();

        $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json();
        $edges = collect($body['edges']);

        $this->assertFalse(
            $edges->contains(fn ($e) => $e['from'] === "ec-{$edge->id}" && $e['to'] === "sw-{$sw2->id}"),
            'sw2 has an LLDP uplink, so it must not be inferred-wired to the SD-WAN',
        );
        // The real sw1 ↔ sw2 LLDP link is present.
        $this->assertTrue($edges->contains(fn ($e) => ($e['lldp'] ?? false)));
    }

    public function test_multi_switch_fabric_attaches_only_its_core_to_the_sdwan(): void
    {
        // #001 shape: a core switch (sw1) with three access switches chaining off
        // it via LLDP. The SD-WAN doesn't advertise LLDP, so it can't be resolved
        // from either side — but the fabric must still attach to the edge through
        // exactly ONE inferred uplink: the core (highest LLDP degree), not all four
        // switches, and not zero (a floating island).
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        $core = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'SWA001']);
        $acc = collect(['SWA002', 'SWA003', 'SWA004'])->map(
            fn ($n) => Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => $n])
        );
        // Each access switch uplinks to the core via LLDP (star fabric).
        foreach ($acc as $i => $a) {
            \App\Models\LldpNeighbor::create(['device_id' => $a->id, 'local_port' => 'ge-0/0/48', 'remote_sysname' => 'SWA001', 'remote_port' => 'ge-0/0/'.$i, 'remote_device_id' => $core->id, 'last_seen_at' => now()]);
        }
        $user = User::factory()->create();

        $edges = collect($this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json('edges'));

        // Exactly one SD-WAN → switch uplink, and it lands on the core.
        $uplinks = $edges->filter(fn ($e) => $e['from'] === "ec-{$edge->id}" && str_starts_with($e['to'], 'sw-'));
        $this->assertCount(1, $uplinks, 'the fabric must attach to the SD-WAN through a single uplink');
        $this->assertSame("sw-{$core->id}", $uplinks->first()['to'], 'the uplink must land on the core switch');
        // No access switch is wired directly to the SD-WAN.
        foreach ($acc as $a) {
            $this->assertFalse($edges->contains(fn ($e) => $e['from'] === "ec-{$edge->id}" && $e['to'] === "sw-{$a->id}"));
        }
    }

    public function test_circuits_pair_to_the_correct_wan_next_hop_without_colliding(): void
    {
        // #001 shape: wan0 = private cable gateway (DHCP/NAT), wan1 = fiber public
        // gateway. The cable circuit's public IP never equals the private gateway,
        // so it must bind by its wan_interface; the fiber binds by monitored IP.
        // Neither may collide onto the other's next-hop.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        $wan0 = \App\Models\DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '192.168.1.1', 'interface' => 'wan0', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        $wan1 = \App\Models\DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '4.42.61.237', 'interface' => 'wan1', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        $cable = Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Spectrum', 'circuit_type' => 'cable', 'wan_interface' => 'wan0', 'monitored_ip' => '131.148.59.234', 'status' => 'up']);
        $fiber = Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Lumen', 'circuit_type' => 'fiber', 'wan_interface' => null, 'monitored_ip' => '4.42.61.237', 'status' => 'up']);
        $user = User::factory()->create();

        $edges = collect($this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json('edges'));
        $cableTo = $edges->firstWhere('from', "isp-{$cable->id}")['to'];
        $fiberTo = $edges->firstWhere('from', "isp-{$fiber->id}")['to'];

        $this->assertSame("nh-{$wan0->id}", $cableTo, 'cable circuit must pair to wan0 by interface');
        $this->assertSame("nh-{$wan1->id}", $fiberTo, 'fiber circuit must pair to wan1 by monitored IP');
        $this->assertNotSame($cableTo, $fiberTo, 'the two circuits must not collide on one next-hop');
    }

    public function test_ha_sdwan_shows_next_hops_only_for_the_active_member(): void
    {
        $site = Site::factory()->create();
        $active = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'SDW1', 'ha_group' => 'cc', 'ha_role' => 'active']);
        $standby = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'SDW2', 'ha_group' => 'cc', 'ha_role' => 'standby']);
        // Even though the standby also reports an up next-hop, it stays hidden while
        // the active is carrying traffic — a passive appliance has no live gateways.
        \App\Models\DeviceNextHop::create(['device_id' => $active->id, 'ip_address' => '4.4.4.4', 'interface' => 'wan0', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        \App\Models\DeviceNextHop::create(['device_id' => $standby->id, 'ip_address' => '5.5.5.5', 'interface' => 'wan0', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        $user = User::factory()->create();

        $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json();
        $nh = collect($body['nodes'])->where('type', 'nexthop');

        $this->assertTrue($nh->contains(fn ($n) => ($n['device_id'] ?? null) === $active->id), 'the active member shows its next-hops');
        $this->assertFalse($nh->contains(fn ($n) => ($n['device_id'] ?? null) === $standby->id), 'the passive standby must not show next-hops');
        $this->assertSame('standby', collect($body['nodes'])->firstWhere('id', "ec-{$standby->id}")['ha_role']);
    }

    public function test_ha_sdwan_fails_over_to_the_standby_when_the_active_goes_down(): void
    {
        // The active's only next-hop is down (appliance down); the standby still has
        // an up next-hop → the standby has taken over and becomes effective-active.
        $site = Site::factory()->create();
        $active = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'SDW1', 'ha_group' => 'cc', 'ha_role' => 'active']);
        $standby = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'SDW2', 'ha_group' => 'cc', 'ha_role' => 'standby']);
        \App\Models\DeviceNextHop::create(['device_id' => $active->id, 'ip_address' => '4.4.4.4', 'interface' => 'wan0', 'reachability' => 'unreachable', 'status' => 'down', 'last_checked_at' => now()]);
        \App\Models\DeviceNextHop::create(['device_id' => $standby->id, 'ip_address' => '5.5.5.5', 'interface' => 'wan0', 'reachability' => 'reachable', 'status' => 'up', 'last_checked_at' => now()]);
        $user = User::factory()->create();

        $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json();
        $nh = collect($body['nodes'])->where('type', 'nexthop');

        $this->assertTrue($nh->contains(fn ($n) => ($n['device_id'] ?? null) === $standby->id), 'the standby (now effective-active) shows its next-hops');
        $this->assertFalse($nh->contains(fn ($n) => ($n['device_id'] ?? null) === $active->id), 'the down active no longer shows next-hops');
        $this->assertSame('standby', collect($body['nodes'])->firstWhere('id', "ec-{$active->id}")['ha_role']);
    }

    public function test_isp_cloud_node_carries_the_lec_details(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Spectrum', 'lec_name' => 'AT&T', 'lec_circuit_id' => 'LEC-8891']);
        $user = User::factory()->create();

        $cloud = collect($this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json('nodes'))
            ->firstWhere('type', 'cloud');

        $this->assertSame('AT&T', $cloud['lec_name']);
        $this->assertSame('LEC-8891', $cloud['lec_circuit_id']);
    }

    public function test_an_stp_blocked_lldp_link_is_flagged(): void
    {
        // sw2 has an alternate LLDP path to sw1 that spanning tree has BLOCKED
        // (stp_state = 2). The topology must flag that edge so it can be drawn as a
        // standby link, not an active one.
        $site = Site::factory()->create();
        $sw1 = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw1']);
        $sw2 = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw2']);
        \App\Models\LldpNeighbor::create([
            'device_id' => $sw2->id, 'local_port' => 'ge-0/0/48', 'remote_sysname' => 'sw1',
            'remote_port' => 'ge-0/0/47', 'remote_device_id' => $sw1->id, 'stp_state' => 2, 'last_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $edges = collect($this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json('edges'));
        $link = $edges->first(fn ($e) => $e['lldp'] ?? false);

        $this->assertNotNull($link);
        $this->assertTrue($link['stp_blocked']);
    }

    public function test_an_lldp_edge_label_shows_only_the_interface_never_the_port_description(): void
    {
        // A neighbour advertises a free-text port description alongside the port
        // ("xe-0/1/3 : UPLINK-TO-CORE"). The edge label must be the interface
        // token only — whatever a poll happened to store.
        $site = Site::factory()->create();
        $sw1 = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw1']);
        $sw2 = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw2']);
        \App\Models\LldpNeighbor::create([
            'device_id' => $sw2->id, 'local_port' => 'xe-0/1/1', 'remote_sysname' => 'sw1',
            'remote_port' => 'xe-0/1/3 : UPLINK-TO-CORE', 'remote_device_id' => $sw1->id, 'last_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $edges = collect($this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json('edges'));
        $link = $edges->first(fn ($e) => $e['lldp'] ?? false);

        $this->assertNotNull($link);
        $this->assertStringNotContainsString('UPLINK', $link['label']);
        $this->assertStringContainsString('xe-0/1/3', $link['label']);
    }

    public function test_a_switch_that_sees_the_sdwan_router_over_lldp_is_the_uplink(): void
    {
        // Branch pattern: the switch cabled to the Silver Peak sees it as an
        // unmanaged 'router' LLDP neighbour on ge-0/0/47. That port IS the SD-WAN
        // uplink — drawn as the switch→SD-WAN link with the real port, and the
        // router is not listed as a plugged-in endpoint.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'sdw']);
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'sw1']);
        \App\Models\LldpNeighbor::create([
            'device_id' => $sw->id, 'local_port' => 'ge-0/0/47', 'remote_sysname' => 'silverpeak',
            'remote_port' => 'lan0', 'remote_device_id' => null, 'neighbor_type' => 'router', 'last_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->json();
        $uplink = collect($body['edges'])->first(fn ($e) => $e['from'] === "ec-{$edge->id}" && $e['to'] === "sw-{$sw->id}");

        $this->assertNotNull($uplink);
        $this->assertStringContainsString('ge-0/0/47', $uplink['label']);
        $this->assertStringContainsString('SD-WAN', $uplink['label']);
        $swNode = collect($body['nodes'])->firstWhere('id', "sw-{$sw->id}");
        $this->assertEmpty(array_filter($swNode['lldp_endpoints'], fn ($e) => $e['type'] === 'router'));
    }

    public function test_healthy_site_reports_no_incident(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up']);
        Device::factory()->create(['site_id' => $site->id, 'role' => 'switch']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology");

        $res->assertOk();
        $res->assertJsonPath('incident.active', false);
    }

    public function test_organization_rollup_flags_impacted_sites(): void
    {
        $this->ispOutageSite();
        $healthy = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $healthy->id, 'status' => 'up']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson('/api/topology');

        $res->assertOk();
        $states = collect($res->json('sites'))->pluck('state');
        $this->assertTrue($states->contains('crit'));
        $this->assertTrue($states->contains('up'));
    }

    public function test_topology_draws_the_real_lldp_link_over_the_inferred_one(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'jax-ec01']);
        $switch = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'jax-sw01']);
        \App\Models\LldpNeighbor::create([
            'device_id' => $switch->id, 'local_port' => 'ge-0/0/5',
            'remote_sysname' => 'jax-ec01', 'remote_port' => 'wan0',
            'remote_device_id' => $edge->id, 'last_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        $body = $this->actingAs($user)->getJson("/api/sites/{$site->id}/topology")->assertOk()->json();

        $ecSw = collect($body['edges'])->first(fn ($e) => $e['from'] === "ec-{$edge->id}" && $e['to'] === "sw-{$switch->id}");
        $this->assertNotNull($ecSw);
        $this->assertTrue($ecSw['lldp'] ?? false);
        $this->assertStringContainsString('ge-0/0/5', $ecSw['label']);

        // The inferred generic "LAN" link for the same pair is suppressed.
        $inferred = collect($body['edges'])->filter(fn ($e) => $e['from'] === "ec-{$edge->id}" && $e['to'] === "sw-{$switch->id}" && ($e['label'] ?? '') === 'LAN');
        $this->assertCount(0, $inferred);
    }

    public function test_guest_cannot_read_topology(): void
    {
        $site = Site::factory()->create();
        $this->getJson("/api/sites/{$site->id}/topology")->assertStatus(401);
        $this->getJson('/api/topology')->assertStatus(401);
    }
}
