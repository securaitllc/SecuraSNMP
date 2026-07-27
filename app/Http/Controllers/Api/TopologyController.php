<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * Derives a logical network topology per site (and a roll-up for the whole
 * organization) from the live inventory: ISP circuits, the Silver Peak edge,
 * its next-hop gateway, the LAN switches and firewalls. Every node/edge carries
 * a live status, and the same-site dependency chain is used to name the root
 * cause of an outage — the ISP underlay — and mark the rest as symptoms.
 *
 * Column order encodes the dependency (kill) chain, upstream → downstream:
 *   0 ISP cloud · 1 next-hop gateway · 2 Silver Peak edge · 3 switch · 4 firewall
 */
class TopologyController extends Controller
{
    public function site(Site $site): JsonResponse
    {
        return response()->json($this->buildSite($site));
    }

    public function organization(): JsonResponse
    {
        $sites = Site::with('hubs:id')->orderBy('name')->get()->map(function (Site $site) {
            $t = $this->buildSite($site);
            $incident = $t['incident'];
            $degraded = collect($t['nodes'])->contains(fn ($n) => $n['status'] !== 'up');

            // A branch homes to N hubs; fall back to the legacy single column.
            $hubIds = $site->hubs->pluck('id')->all();
            if ($hubIds === [] && $site->hub_site_id) {
                $hubIds = [$site->hub_site_id];
            }

            return [
                'id' => $site->id,
                'name' => $site->name,
                'address' => $site->address,
                'site_type' => $site->site_type,
                'hub_site_id' => $site->hub_site_id,
                'hub_site_ids' => $hubIds,
                'state' => $incident['active'] ? 'crit' : ($degraded ? 'warn' : 'up'),
                // Down flags along the chain, for the org card's mini-topology.
                'chain' => [
                    'cloud' => collect($t['nodes'])->where('type', 'cloud')->contains(fn ($n) => $n['status'] === 'down'),
                    'gw' => collect($t['nodes'])->where('type', 'gw')->contains(fn ($n) => $n['status'] === 'down'),
                    'edge' => collect($t['nodes'])->where('type', 'edge')->contains(fn ($n) => $n['status'] !== 'up'),
                    'switch' => collect($t['nodes'])->where('type', 'switch')->contains(fn ($n) => $n['status'] !== 'up'),
                ],
                'summary' => $incident['active']
                    ? $incident['summary']
                    : ($degraded ? 'Degraded' : 'Healthy'),
                'device_count' => collect($t['nodes'])->whereNotIn('type', ['cloud', 'gw'])->count(),
            ];
        });

        return response()->json(['sites' => $sites->values()]);
    }

    /**
     * @return array{site: array, nodes: array, edges: array, incident: array}
     */
    private function buildSite(Site $site): array
    {
        $site->load([
            'circuits.ispProvider:id,support_phone',
            'sharedCircuits.ispProvider:id,support_phone',
            'sharedCircuits.site:id,name',
            'devices.tunnels',
            'devices.nextHopAlerts' => fn ($q) => $q->whereNull('ended_at'),
            'devices.alarms' => fn ($q) => $q->whereNull('cleared_at'),
            'devices.health',
            'devices.interfaces:id,device_id,if_name,status,admin_status,alarm_suppressed',
            'devices.interfaces.alerts' => fn ($q) => $q->whereNull('ended_at'),
            'devices.lldpNeighbors',
            'devices.nextHops',
        ]);

        $nodes = [];
        $edges = [];

        $edgeDevices = $site->devices->where('role', 'edgeconnect')->values();
        $switches = $site->devices->whereIn('role', ['switch', 'router'])->values();
        $firewalls = $site->devices->where('role', 'firewall')->values();

        // Circuits serving this site = its own + any shared TO it from another site.
        $sharedIds = $site->sharedCircuits->pluck('id')->all();
        $circuits = $site->circuits->concat($site->sharedCircuits)->unique('id')->values();

        // --- ISP clouds (one per circuit) ---
        foreach ($circuits as $c) {
            $isShared = in_array($c->id, $sharedIds, true);
            $nodes[] = [
                'id' => "isp-{$c->id}",
                'type' => 'cloud',
                'label' => $c->isp_name,
                'sub' => $c->circuit_id.($isShared ? ' · shared' : ''),
                'status' => $c->status === 'down' ? 'down' : 'up',
                'col' => 0,
                'ip' => $c->monitored_ip,
                'model' => $this->circuitTypeLabel($c->circuit_type),
                'role' => 'ISP · '.$this->circuitTypeLabel($c->circuit_type).($isShared ? ' · shared from '.optional($c->site)->name : ''),
                'circuit_id' => $c->id,
                'support_phone' => optional($c->ispProvider)->support_phone ?? $c->support_phone,
                // Local Exchange Carrier — the company that actually owns the last
                // mile (distinct from the ISP contract), i.e. who to call for a
                // physical line fault.
                'lec_name' => $c->lec_name,
                'lec_circuit_id' => $c->lec_circuit_id,
            ];
        }

        // --- Silver Peak edge(s) + their next-hops ---
        // A next-hop on a WAN interface (wan0/wan1) is the ISP gateway and belongs
        // upstream, in the WAN area (col 1). A next-hop on a LAN interface
        // (lan0/tlan0 — physically cabled to the Juniper per LLDP) is the LAN-side
        // gateway and belongs in the LAN area (col 3), hung off the edge — NOT in
        // the ISP chain, where it would wrongly read as an internet path.
        // HA SD-WAN: group the edges by ha_group and pick each group's EFFECTIVE
        // active — the member actually carrying traffic. Normally the active-role
        // member; but if it has lost all its WAN next-hops (appliance down) while a
        // standby still has an up next-hop, the standby has taken over and becomes
        // effective-active. Only the effective-active shows next-hops; the passive
        // standby contributes none (a passive appliance has no live gateways).
        $edgeStandbyIds = [];   // device_id => true (render as standby, no next-hops)
        $hasUpNextHop = fn (Device $d) => $d->nextHops->contains(fn ($nh) => $nh->status !== 'down');
        $edgeGroups = [];
        foreach ($edgeDevices as $d) {
            if ($d->ha_group) {
                $edgeGroups[$d->ha_group][] = $d;
            }
        }
        $edgeActiveOfGroup = [];  // ha_group => effective-active device id
        foreach ($edgeGroups as $gkey => $members) {
            $members = collect($members);
            $active = $members->firstWhere('ha_role', 'active') ?? $members->first();
            // Failover: keep the active unless it's down and another member is up.
            $effective = $hasUpNextHop($active)
                ? $active
                : ($members->first(fn ($m) => $hasUpNextHop($m)) ?? $active);
            $edgeActiveOfGroup[$gkey] = $effective->id;
            foreach ($members as $m) {
                if ($m->id !== $effective->id) {
                    $edgeStandbyIds[$m->id] = true;
                }
            }
        }

        $nhByEdge = [];    // device_id => WAN next-hops [['id','status','ip'], ...]
        $lanNhByEdge = []; // device_id => LAN next-hops [['id','status'], ...]
        foreach ($edgeDevices as $d) {
            $isStandby = isset($edgeStandbyIds[$d->id]);
            $downTunnels = $this->downTunnelCount($d);
            $sshDownTunnels = $d->tunnels->where('status', 'down')->count();
            // The down count came from the SNMP alarm (not the SSH table) when SSH
            // shows none down but downTunnels is still positive — label it honestly
            // rather than inventing a "1/N down" fraction the SSH detail contradicts.
            $tunnelsFromSnmpOnly = $downTunnels > 0 && $sshDownTunnels === 0;
            $totalTunnels = max($d->tunnels->count(), $downTunnels);
            // SNMP alarms are the fast, authoritative WAN signal; a lagging SSH
            // next-hop alert on a reachable appliance is ignored (see edgeWanDown).
            $nextHopDown = $this->edgeWanDown($d);
            // WANs whose IP-SLA is down (reachable next-hop but no internet) — their
            // next-hop pill must read down even though the appliance ARPs it up.
            $slaDownWans = $this->ipSlaDownWans($d);

            // A passive standby has no live gateways — render the appliance, but no
            // next-hop pills and no WAN chain hanging off it.
            if ($isStandby) {
                $nhByEdge[$d->id] = [];
                $lanNhByEdge[$d->id] = [];
                $nodes[] = [
                    'id' => "ec-{$d->id}",
                    'type' => 'edge',
                    'label' => $d->name,
                    'sub' => 'Standby · '.($d->model ?? 'EdgeConnect'),
                    'status' => 'up',
                    'col' => 2,
                    'ip' => $d->ip_address,
                    'model' => trim(($d->vendor ? ucfirst($d->vendor).' ' : '').($d->model ?? '')) ?: 'Silver Peak',
                    'role' => 'Silver Peak EdgeConnect · HA standby',
                    'device_id' => $d->id,
                    'serial' => $d->serial_number,
                    'health' => $this->healthOf($d),
                    'ha_role' => 'standby',
                    'tunnels' => null,
                ];

                continue;
            }

            // Next-hops the SP reports (small logical gateway markers — NOT device
            // cards). Fall back to a single WAN placeholder if none yet.
            $nhList = [];
            $lanList = [];
            if ($d->nextHops->isNotEmpty()) {
                foreach ($d->nextHops as $nh) {
                    // The DeviceNextHop.status is set by the slow SSH sweep too, so a
                    // recovered next-hop reads 'down' until the sweep catches up. Only
                    // show a pill down when the authoritative device-level signal
                    // agrees the WAN is down (edgeWanDown) — else it's a stale straggler.
                    // A WAN whose IP-SLA is down reads down even though it ARPs up.
                    $slaDown = in_array(strtolower((string) $nh->interface), $slaDownWans, true);
                    $st = (($nh->status === 'down' && $nextHopDown) || $slaDown) ? 'down' : 'up';
                    $isLan = (bool) preg_match('/^t?lan/i', (string) $nh->interface);
                    $nodes[] = [
                        'id' => "nh-{$nh->id}", 'type' => 'nexthop',
                        'label' => $nh->ip_address, 'sub' => $nh->interface ?: 'next-hop',
                        'status' => $st, 'col' => $isLan ? 3 : 1, 'ip' => $nh->ip_address, 'model' => $nh->reachability ?: '—',
                        'role' => ($isLan ? 'LAN next-hop' : 'ISP next-hop').($nh->interface ? " · {$nh->interface}" : ''),
                        'device_id' => $d->id,
                    ];
                    if ($isLan) {
                        $lanList[] = ['id' => "nh-{$nh->id}", 'status' => $st];
                    } else {
                        $nhList[] = ['id' => "nh-{$nh->id}", 'status' => $st, 'ip' => $nh->ip_address, 'interface' => $nh->interface];
                    }
                }
            } else {
                $nodes[] = [
                    'id' => "gw-{$d->id}", 'type' => 'nexthop',
                    'label' => $d->next_hop_ip ?: 'Next-hop', 'sub' => $d->next_hop_ip ? 'next-hop' : 'not set',
                    'status' => $nextHopDown ? 'down' : 'up', 'col' => 1, 'ip' => $d->next_hop_ip ?: '—', 'model' => '—',
                    'role' => 'ISP next-hop', 'device_id' => $d->id,
                ];
                $nhList[] = ['id' => "gw-{$d->id}", 'status' => $nextHopDown ? 'down' : 'up', 'ip' => $d->next_hop_ip];
            }
            $nhByEdge[$d->id] = $nhList;
            $lanNhByEdge[$d->id] = $lanList;

            $isHa = (bool) $d->ha_group;
            $nodes[] = [
                'id' => "ec-{$d->id}",
                'type' => 'edge',
                'label' => $d->name,
                'sub' => ($isHa ? 'Active · ' : '').($d->model ?? 'EdgeConnect'),
                'status' => ($downTunnels > 0 || $nextHopDown) ? 'warn' : 'up',
                'col' => 2,
                'ip' => $d->ip_address,
                'model' => trim(($d->vendor ? ucfirst($d->vendor).' ' : '').($d->model ?? '')) ?: 'Silver Peak',
                'role' => 'Silver Peak EdgeConnect'.($isHa ? ' · HA active' : ''),
                'device_id' => $d->id,
                'serial' => $d->serial_number,
                'health' => $this->healthOf($d),
                'ha_role' => $isHa ? 'active' : null,
                'tunnels' => $totalTunnels > 0
                    ? ($tunnelsFromSnmpOnly
                        ? 'tunnels down (SNMP alarm)'
                        : ($downTunnels > 0 ? "{$downTunnels}/{$totalTunnels} tunnels down" : "{$totalTunnels} tunnels up"))
                    : null,
                // Overlay tunnels grouped by hub — each hub is up unless any of its
                // tunnels is down, so the operator sees per-hub SD-WAN health.
                'tunnel_hubs' => $d->tunnels->whereNotNull('hub')->groupBy('hub')->map(fn ($ts, $hub) => [
                    'hub' => $hub,
                    'total' => $ts->count(),
                    'down' => $ts->where('status', 'down')->count(),
                ])->values()->all(),
                // The appliance's SNMP says tunnels are down but the (slow) SSH table
                // still shows them all up — the per-hub breakdown above is STALE. The
                // UI must warn instead of drawing a misleading all-green grid.
                'tunnels_stale' => $tunnelsFromSnmpOnly,
            ];
        }

        // HA link: connect the active appliance to each standby peer so the pair
        // reads as one redundant unit (drawn as a dashed sync link between them).
        foreach ($edgeGroups as $gkey => $members) {
            $activeId = $edgeActiveOfGroup[$gkey] ?? null;
            if (! $activeId) {
                continue;
            }
            foreach ($members as $m) {
                if ($m->id !== $activeId) {
                    $edges[] = ['from' => "ec-{$activeId}", 'to' => "ec-{$m->id}", 'label' => 'HA', 'status' => 'up', 'ha' => true];
                }
            }
        }

        // --- switches + firewalls (HA members collapse into one node) ---
        $switchUnits = $this->haUnits($switches);
        $fwUnits = $this->haUnits($firewalls);
        foreach ($switchUnits as $unit) {
            $nodes[] = $this->lanNode($unit, 'switch', 3);
        }
        foreach ($fwUnits as $unit) {
            $nodes[] = $this->lanNode($unit, 'fw', 4);
        }

        // --- discovered LLDP adjacencies: real links between this site's devices ---
        // Map EVERY device id (including collapsed HA standby members) to its node,
        // so a link discovered on a standby resolves to the unit's node.
        $deviceNode = [];
        foreach ($nodes as $n) {
            if (! isset($n['device_id']) || ! in_array($n['type'], ['edge', 'switch', 'fw'], true)) {
                continue;
            }
            foreach ($n['ha_member_ids'] ?? [$n['device_id']] as $mid) {
                $deviceNode[$mid] = ['id' => $n['id'], 'col' => $n['col']];
            }
        }
        $lldpPairs = [];   // pair key => index into $edges
        foreach ($site->devices as $d) {
            foreach ($d->lldpNeighbors as $nb) {
                $a = $deviceNode[$d->id] ?? null;
                $b = $nb->remote_device_id ? ($deviceNode[$nb->remote_device_id] ?? null) : null;
                if (! $a || ! $b || $a['id'] === $b['id']) {
                    continue;
                }
                // Spanning tree has blocked this local port (dot1dStpPortState = 2):
                // the link is physically up but a standby path, not forwarding.
                $blocked = ((int) $nb->stp_state) === 2;
                $pair = min($d->id, $nb->remote_device_id).'-'.max($d->id, $nb->remote_device_id);
                if (isset($lldpPairs[$pair])) {
                    // Second row for the same link — STP blocks on one end, so if
                    // THIS end is blocked, flag the already-drawn edge.
                    if ($blocked) {
                        $edges[$lldpPairs[$pair]]['stp_blocked'] = true;
                    }

                    continue;
                }
                // Orient the edge upstream→downstream (lower column first) so it
                // renders left-to-right; label ports local-first for that end.
                [$from, $fromPort, $to, $toPort] = $a['col'] <= $b['col']
                    ? [$a['id'], $nb->local_port, $b['id'], $nb->remote_port]
                    : [$b['id'], $nb->remote_port, $a['id'], $nb->local_port];
                // Interface token only — never the free-text port description a switch
                // may advertise (e.g. "xe-0/1/3 : UPLINK-TO-CORE"). Sanitize at render
                // so no matter what a poll stored, the label shows just the port.
                $label = trim($this->ifToken($fromPort).' · '.$this->ifToken($toPort), " \u{00b7}") ?: 'LLDP';
                $edges[] = ['from' => $from, 'to' => $to, 'label' => $label, 'status' => 'up', 'lldp' => true, 'stp_blocked' => $blocked];
                $lldpPairs[$pair] = count($edges) - 1;
            }
        }

        // --- edges (dependency chain) ---
        // The primary edge (WAN chain, circuit pairing, incident root) is the
        // effective-active appliance, never a passive standby.
        $edgeDev = $edgeDevices->first(fn ($d) => ! isset($edgeStandbyIds[$d->id])) ?? $edgeDevices->first();
        $firstSwitchId = isset($switchUnits[0]) ? 'sw-'.$switchUnits[0]->first()->id : null;
        $nhList = $edgeDev ? ($nhByEdge[$edgeDev->id] ?? []) : [];
        $ecId = $edgeDev ? "ec-{$edgeDev->id}" : null;

        // ISP cloud → its WAN next-hop. Deterministic two-pass pairing so an
        // unmatched circuit can't collide onto a next-hop another circuit clearly
        // owns (the "both circuits point at wan1" bug):
        //   pass 1 — bind circuits with a definite signal: the circuit's WAN
        //            interface matching a next-hop's interface (wan0 ↔ wan0), then
        //            its monitored IP matching a next-hop IP;
        //   pass 2 — give each still-unbound circuit the next unused next-hop.
        // A DHCP circuit behind ISP NAT (public IP never equals the private
        // gateway) only ever binds by interface — which is exactly why the cable
        // circuit needs its wan_interface set.
        $pairing = [];
        $usedNh = [];
        if ($edgeDev && $nhList) {
            foreach ($circuits as $c) {
                foreach ($nhList as $x) {
                    if ($c->wan_interface && ($x['interface'] ?? null) === $c->wan_interface && ! in_array($x['id'], $usedNh, true)) {
                        $pairing[$c->id] = $x['id'];
                        $usedNh[] = $x['id'];
                        continue 2;
                    }
                }
                foreach ($nhList as $x) {
                    if ($x['ip'] && $x['ip'] === $c->monitored_ip && ! in_array($x['id'], $usedNh, true)) {
                        $pairing[$c->id] = $x['id'];
                        $usedNh[] = $x['id'];
                        continue 2;
                    }
                }
            }
            $ci = 0;
            foreach ($circuits as $c) {
                if (! isset($pairing[$c->id])) {
                    $pick = null;
                    foreach ($nhList as $x) {
                        if (! in_array($x['id'], $usedNh, true)) {
                            $pick = $x['id'];
                            break;
                        }
                    }
                    $pick = $pick ?? ($nhList[$ci]['id'] ?? $nhList[count($nhList) - 1]['id']);
                    $pairing[$c->id] = $pick;
                    $usedNh[] = $pick;
                }
                $ci++;
            }
        }
        foreach ($circuits as $c) {
            $cloudId = "isp-{$c->id}";
            $circuitDown = $c->status === 'down';
            if ($edgeDev && $nhList && isset($pairing[$c->id])) {
                $edges[] = ['from' => $cloudId, 'to' => $pairing[$c->id], 'label' => 'circuit', 'status' => $circuitDown ? 'down' : 'up'];
            } elseif ($edgeDev) {
                $edges[] = ['from' => $cloudId, 'to' => $ecId, 'label' => 'circuit', 'status' => $circuitDown ? 'down' : 'up'];
            } elseif ($firstSwitchId) {
                $edges[] = ['from' => $cloudId, 'to' => $firstSwitchId, 'label' => 'circuit', 'status' => $circuitDown ? 'down' : 'up'];
            }
        }
        // each WAN next-hop → the SP (status = the next-hop's reachability).
        foreach ($nhList as $nh) {
            $edges[] = ['from' => $nh['id'], 'to' => $ecId, 'label' => 'next-hop', 'status' => $nh['status']];
        }
        // LAN-side next-hop(s): the SP's LAN gateway, drawn downstream of the edge
        // (SP → LAN next-hop) in the LAN area, not as an ISP path.
        foreach (($edgeDev ? ($lanNhByEdge[$edgeDev->id] ?? []) : []) as $lanNh) {
            $edges[] = ['from' => $ecId, 'to' => $lanNh['id'], 'label' => 'LAN gateway', 'status' => $lanNh['status']];
        }

        // The switch fabric forms LLDP-connected clusters (a core switch with
        // access switches chaining off it). LLDP already drew every switch↔switch
        // link inside a cluster; what LLDP CAN'T see is the SD-WAN → core uplink,
        // because the Silver Peak doesn't advertise LLDP the Junipers resolve. So
        // we infer exactly ONE uplink per cluster — from the core (highest LLDP
        // degree) to the edge — instead of wiring every switch to the SD-WAN (the
        // old "everything hangs off the SD-WAN" bug) OR suppressing all uplinks and
        // leaving the fabric floating.
        $swUnitById = [];
        $primaryOf = [];   // any switch member id => its unit's primary id
        $adj = [];         // core-fabric adjacency: primary id => set of primary ids
        foreach ($switchUnits as $u) {
            $swUnitById[$u->first()->id] = $u;
            $adj[$u->first()->id] = [];
            foreach ($u as $m) {
                $primaryOf[$m->id] = $u->first()->id;
            }
        }
        // Branch SD-WAN uplink: a switch that sees a ROUTER over LLDP (the Silver
        // Peak advertises router capability) is the one physically cabled to the
        // SD-WAN LAN — that local port (e.g. ge-0/0/47) is the uplink. Detect it so
        // the topology draws the real switch→SD-WAN link with its port, and treats
        // that switch as the cluster core. Works for every branch.
        $switchUplinkPort = [];
        foreach ($switchUnits as $u) {
            foreach ($u as $m) {
                $r = $m->lldpNeighbors->first(fn ($nb) => $nb->neighbor_type === 'router' && $nb->remote_device_id === null);
                if ($r) {
                    $switchUplinkPort[$u->first()->id] = $r->local_port;
                    break;
                }
            }
        }
        foreach ($switchUnits as $u) {
            foreach ($u as $m) {
                foreach ($m->lldpNeighbors as $nb) {
                    if ($nb->remote_device_id && isset($primaryOf[$nb->remote_device_id])) {
                        $a = $u->first()->id;
                        $b = $primaryOf[$nb->remote_device_id];
                        if ($a !== $b) {
                            $adj[$a][$b] = true;
                            $adj[$b][$a] = true;
                        }
                    }
                }
            }
        }
        // Connected components of the switch fabric (isolated switch = its own).
        $seen = [];
        $components = [];
        foreach (array_keys($adj) as $sid) {
            if (isset($seen[$sid])) {
                continue;
            }
            $stack = [$sid];
            $comp = [];
            while ($stack) {
                $x = array_pop($stack);
                if (isset($seen[$x])) {
                    continue;
                }
                $seen[$x] = true;
                $comp[] = $x;
                foreach (array_keys($adj[$x]) as $y) {
                    if (! isset($seen[$y])) {
                        $stack[] = $y;
                    }
                }
            }
            // Core first: the switch that uplinks to the SD-WAN (router LLDP
            // neighbour) wins; else highest LLDP degree, tie-break lowest id.
            usort($comp, function ($p, $q) use ($adj, $switchUplinkPort) {
                $up = (isset($switchUplinkPort[$q]) <=> isset($switchUplinkPort[$p]));

                return $up !== 0 ? $up : ((count($adj[$q]) <=> count($adj[$p])) ?: ($p <=> $q));
            });
            $components[] = $comp;
        }

        // Hierarchy: the cluster core stays in the switch column (3); access
        // switches that chain off it move one column right (4), so a multi-switch
        // fabric reads SD-WAN → core → access left-to-right instead of a flat
        // vertical stack with crossing links.
        $accessIds = [];
        foreach ($components as $comp) {
            foreach (array_slice($comp, 1) as $sid) {
                $accessIds[$sid] = true;
            }
        }
        if ($accessIds) {
            foreach ($nodes as &$nn) {
                if (($nn['type'] ?? '') === 'switch' && isset($accessIds[$nn['device_id'] ?? null])) {
                    $nn['col'] = 4;
                }
            }
            unset($nn);
        }

        // edge → each fabric cluster's core (LAN uplink). Skip a cluster that
        // already has a switch with a REAL resolved LLDP link to the edge.
        if ($edgeDev) {
            $edgeIds = $edgeDevices->pluck('id')->all();
            foreach ($components as $comp) {
                $lldpToEdge = false;
                foreach ($comp as $sid) {
                    if ($swUnitById[$sid]->contains(fn ($m) => $m->lldpNeighbors->contains(fn ($nb) => in_array($nb->remote_device_id, $edgeIds, true)))) {
                        $lldpToEdge = true;
                        break;
                    }
                }
                if ($lldpToEdge) {
                    continue;
                }
                $core = $comp[0];
                $pair = min($edgeDev->id, $core).'-'.max($edgeDev->id, $core);
                if (isset($lldpPairs[$pair])) {
                    continue;
                }
                // Real port when the core sees the SD-WAN router over LLDP.
                $uplinkPort = $switchUplinkPort[$core] ?? null;
                $label = $uplinkPort ? ($uplinkPort.' · SD-WAN') : (count($comp) > 1 ? 'LAN · uplink' : 'LAN');
                $edges[] = ['from' => "ec-{$edgeDev->id}", 'to' => "sw-{$core}", 'label' => $label, 'status' => 'up'];
            }
            // SD-WAN overlay rides every underlay, so draw one overlay arc per ISP
            // cloud back to the appliance.
            $downTunnels = $this->downTunnelCount($edgeDev);
            foreach ($circuits as $c) {
                $edges[] = ['from' => "ec-{$edgeDev->id}", 'to' => "isp-{$c->id}", 'label' => 'SD-WAN overlay', 'status' => $downTunnels > 0 ? 'down' : 'up', 'overlay' => true];
            }
        }
        // fabric core → firewalls (trunk). Only the cluster core trunks to the
        // firewall (not every access switch), and only when LLDP hasn't mapped it.
        foreach ($components as $comp) {
            $core = $comp[0];
            foreach ($fwUnits as $fwUnit) {
                $fwId = $fwUnit->first()->id;
                $pair = min($core, $fwId).'-'.max($core, $fwId);
                if (isset($lldpPairs[$pair])) {
                    continue;
                }
                $edges[] = ['from' => "sw-{$core}", 'to' => "fw-{$fwId}", 'label' => 'trunk', 'status' => 'up'];
            }
        }

        $firstNhId = $edgeDev ? ($nhByEdge[$edgeDev->id][0]['id'] ?? null) : null;
        $incident = $this->rootCause($site, $edgeDev, $edges, $firstNhId, $circuits);

        // Mark the root-cause edge for the badge.
        if ($incident['active'] && $incident['root_edge']) {
            foreach ($edges as &$e) {
                if ($e['from'] === $incident['root_edge']['from'] && $e['to'] === $incident['root_edge']['to']) {
                    $e['root'] = true;
                }
            }
            unset($e);
        }

        return [
            'site' => ['id' => $site->id, 'name' => $site->name, 'address' => $site->address],
            'nodes' => $nodes,
            'edges' => $edges,
            'incident' => collect($incident)->except('root_edge')->all(),
        ];
    }

    /**
     * Live health for the selected-node panel: CPU / RAM / temperature / uptime.
     *
     * @return array<string, mixed>|null
     */
    private function healthOf(Device $d): ?array
    {
        $h = $d->health;
        if (! $h) {
            return null;
        }

        return [
            'cpu_pct' => $h->cpu_pct,
            'mem_pct' => $h->mem_pct,
            'temperature_c' => $h->temperature_c,
            'uptime_seconds' => $h->uptime_seconds,
        ];
    }

    /**
     * Collapse a device collection into logical units: devices sharing an
     * ha_group become one unit (an HA pair/cluster); everything else is a unit of
     * one. The "active" member (or the first) is the unit's primary.
     *
     * @return array<int, \Illuminate\Support\Collection<int, Device>>
     */
    private function haUnits($devices): array
    {
        $units = [];
        $singles = [];
        $groups = [];
        foreach ($devices as $d) {
            if ($d->ha_group) {
                $groups[$d->ha_group][] = $d;
            } else {
                $singles[] = collect([$d]);
            }
        }
        foreach ($groups as $members) {
            // Active member first, so it's the primary for the WAN chain / labels.
            $units[] = collect($members)->sortByDesc(fn ($m) => $m->ha_role === 'active')->values();
        }

        return array_merge($units, $singles);
    }

    /** True when this member has no open (unsuppressed) interface-down alert and no active alarm. */
    private function memberHealthy(Device $d): bool
    {
        $ifDown = $d->interfaces->filter(fn ($i) => ! $i->alarm_suppressed && $i->alerts->isNotEmpty())->count();

        return $ifDown === 0 && $d->alarms->count() === 0 && $d->status === 'active';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Device>  $unit
     */
    /** Human circuit-transport label. */
    private function circuitTypeLabel(?string $type): string
    {
        return ['fiber' => 'Fiber', 'cable' => 'Cable Modem', 'lte' => 'LTE'][$type] ?? ucfirst($type ?? 'circuit');
    }

    private function lanNode($unit, string $type, int $col): array
    {
        $d = $unit->first();               // primary
        $ha = $unit->count() > 1;
        $healthy = $unit->filter(fn ($m) => $this->memberHealthy($m))->count();

        // Redundancy-aware status: all healthy = up; some (HA) = degraded but the
        // pair still carries; none = down (a real outage, both members impacted).
        $status = $healthy === $unit->count()
            ? 'up'
            : ($healthy > 0 ? 'warn' : ($ha ? 'down' : 'warn'));

        // Node-id prefix: switches use "sw-" to match the LAN/trunk edge refs
        // ("switch-" here would silently orphan those edges). Firewalls use "fw-".
        $prefix = $type === 'switch' ? 'sw' : $type;
        $label = $ha ? $unit->pluck('name')->implode(' / ') : $d->name;

        return [
            'id' => "{$prefix}-{$d->id}",
            'type' => $type,
            'label' => $label,
            'sub' => $d->model ?? ($type === 'fw' ? 'Firewall' : 'Switch'),
            'status' => $status,
            'col' => $col,
            'ip' => $d->ip_address,
            'model' => trim(($d->vendor ? ucfirst($d->vendor).' ' : '').($d->model ?? '')) ?: '—',
            'role' => ($ha ? 'HA ' : '').($type === 'fw' ? 'Firewall' : 'Switch'),
            'device_id' => $d->id,
            'serial' => $d->serial_number,
            'health' => $this->healthOf($d),
            'ha' => $ha,
            'ha_members' => $ha ? $unit->map(fn ($m) => ['name' => $m->name, 'role' => $m->ha_role, 'status' => $this->memberHealthy($m) ? 'up' : 'down'])->all() : null,
            'ha_member_ids' => $unit->pluck('id')->all(),
            // Unmanaged LLDP neighbors (Mist APs, PoE phones, endpoints) with the
            // switch port they're on — what's plugged into this switch. A 'router'
            // neighbour is the SD-WAN uplink (drawn as its own link), not an endpoint.
            'lldp_endpoints' => $unit->flatMap(fn ($m) => $m->lldpNeighbors)
                ->filter(fn ($nb) => $nb->remote_device_id === null && $nb->neighbor_type !== 'router')
                ->map(fn ($nb) => [
                    'port' => $this->ifToken($nb->local_port),
                    'name' => $nb->remote_sysname,
                    'type' => $nb->neighbor_type ?: 'other',
                    'remote_port' => $this->ifToken($nb->remote_port),
                    'ip' => $nb->remote_mgmt_addr,
                    // Endpoint identity parsed out of what the neighbour advertises —
                    // the extension is what a user quotes when they report a fault.
                    'extension' => $nb->extension,
                    'model' => $nb->endpoint_model,
                    'mac' => $nb->remote_mac,
                ])->values()->all(),
            // The unsuppressed interface-down alarms on this switch — which ports
            // are down and since when — so the operator can see and act on them.
            'alarmed_interfaces' => $unit->flatMap(fn ($m) => $m->interfaces)
                ->filter(fn ($i) => ! $i->alarm_suppressed && $i->alerts->isNotEmpty())
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->if_name,
                    'alert_id' => optional($i->alerts->first())->id,
                    'ticket' => optional($i->alerts->first())->ticket_number,
                    'acknowledged' => optional($i->alerts->first())->acknowledged_at !== null,
                    'since' => optional($i->alerts->first())->started_at,
                ])->values()->all(),
        ];
    }

    /**
     * The interface token out of an LLDP port string — drops any trailing free-text
     * description a neighbour advertises (lldpRemPortDesc), e.g.
     * "xe-0/1/3 : UPLINK-TO-CORE" -> "xe-0/1/3". A pure-numeric or unrecognised
     * value is returned untouched (a raw port number, not a description).
     */
    /**
     * How many of an edge's SD-WAN tunnels are down. The per-tunnel SSH table is
     * authoritative and carries the per-hub breakdown, so use it whenever the
     * appliance has SSH tunnel data; only fall back to the SNMP 'tunnel_down'
     * rollup alarm (which has no per-tunnel/hub detail) for appliances whose
     * tunnels aren't SSH-polled. This keeps the summary count consistent with the
     * "tunnels by hub" panel — a stale rollup alarm no longer shows "1 down" while
     * every hub reads 0.
     */
    private function downTunnelCount(?Device $d): int
    {
        if (! $d) {
            return 0;
        }

        // Policy: SNMP is the authoritative, real-time signal (the 90s alarm loop);
        // SSH only CONFIRMS and adds per-tunnel detail, and its sweep can be hours
        // stale — a stale SSH table showing "all up" must never hide a live SNMP
        // tunnel alarm (that made a site read Healthy with a critical alarm open).
        // So: use the SSH table's specific count when it actually shows downs;
        // otherwise an active SNMP tunnel-down alarm decides the site has a problem.
        $sshDown = $d->tunnels->where('status', 'down')->count();
        if ($sshDown > 0) {
            return $sshDown;
        }

        $snmpTunnelDown = $d->alarms->contains(fn ($a) => str_contains((string) $a->alarm_id, ':Tunnel')
            || str_contains((string) $a->alarm_id, 'tunnel_down'));

        return $snmpTunnelDown ? 1 : 0;
    }

    /**
     * Is any WAN uplink / next-hop on this edge down RIGHT NOW?
     *
     * Two signals detect a down WAN, at very different speeds:
     *   - The appliance's OWN SNMP alarms — a gateway/next-hop-unreachable alarm
     *     (ec:<t>:gw:<ip>) or a WAN link-down alarm (ec:<t>:wanN) — fire on the
     *     90s alarm loop. Fast.
     *   - The SSH `show system nexthops` poller, which probes each gateway from
     *     the appliance itself. It catches a dead gateway even when the link is
     *     up and no SNMP alarm fires (a local-access/last-mile fault), so it's an
     *     independent detector we must NOT ignore.
     *
     * The SSH poller sweeps ~140 appliances sequentially, so a sweep takes
     * minutes and its NextHopAlert lingers long after the WAN recovers — a
     * restored site kept reading "next-hop DOWN" for minutes. The fix targets
     * only that staleness: if a WAN/gw SNMP alarm has CLEARED at or after the SSH
     * alert opened, the fast SNMP loop already confirmed the WAN is back, so the
     * still-open SSH alert is a straggler → treat as up. When no SNMP alarm ever
     * fired, the SSH poller is the sole detector and is honored as-is.
     */
    private function edgeWanDown(?Device $d): bool
    {
        if (! $d) {
            return false;
        }
        // Live WAN/gw SNMP alarm → down now (fast, authoritative).
        if ($d->alarms->contains(fn ($a) => (bool) preg_match('/:gw:|:wan\d/i', (string) $a->alarm_id))) {
            return true;
        }
        // The appliance's IP-SLA can't reach the internet out a WAN — the next-hop
        // gateway ARPs fine ("reachable") but real traffic (ping 8.8.8.8 -I wanN)
        // fails. That's a WAN down even though `show system nexthops` says up.
        if ($this->ipSlaDownWans($d) !== []) {
            return true;
        }
        // No open SSH next-hop alert → up.
        $openedAt = $d->nextHopAlerts->min('started_at');
        if ($openedAt === null) {
            return false;
        }
        // SSH says a next-hop is down. Stale straggler, or a real SSH-only fault?
        // Stale iff a WAN/gw SNMP alarm cleared at/after this alert opened.
        $recovered = DeviceAlarm::where('device_id', $d->id)
            ->whereNotNull('cleared_at')
            ->where('cleared_at', '>=', $openedAt)
            ->where(fn ($q) => $q->where('alarm_id', 'like', '%:gw:%')->orWhere('alarm_id', 'like', '%:wan%'))
            ->exists();

        return ! $recovered;
    }

    /**
     * WAN interfaces whose IP-SLA monitor is DOWN — the appliance's own end-to-end
     * internet reachability test ("Ping for sp-ipsla…,8.8.8.8… on Port wanN") is
     * failing, so that WAN can't pass traffic even though its next-hop gateway is
     * reachable at layer 2/3. Returns lowercased wan tokens (e.g. ['wan0']).
     *
     * @return list<string>
     */
    private function ipSlaDownWans(?Device $d): array
    {
        if (! $d) {
            return [];
        }
        $wans = [];
        foreach ($d->alarms as $a) {
            $id = (string) $a->alarm_id;
            if ((stripos($id, 'ipsla') !== false || stripos($id, 'Ping for') !== false)
                && preg_match('/\bPort (wan\d+)/i', $id, $m)) {
                $wans[strtolower($m[1])] = true;
            }
        }

        return array_keys($wans);
    }

    /**
     * Remediation wording for a down WAN, correct for WHY it's down:
     *   - link down (the wanN interface itself): check the appliance WAN port and
     *     the cable to the modem/ONT first — that's the local side.
     *   - reachable gateway but no traffic (IP-SLA down): do NOT chase the local
     *     cable. The gateway usually sits at the ISP head-end, so it pinging does
     *     not mean the circuit is good — reboot the modem, then open an ISP ticket
     *     to check the circuit path.
     */
    private function wanDownAction(bool $isLinkDown, bool $onBackup): string
    {
        $tail = $onBackup ? ' The SD-WAN is still passing traffic on its backup, but there is no redundancy left.' : '';

        if ($isLinkDown) {
            return 'The WAN interface link is down — check the appliance WAN port and the physical cable to the cable modem / ONT first. If those are good, open an ISP ticket for the modem / fiber.'.$tail;
        }

        return 'The gateway still answers but the circuit is not passing traffic to the internet — and the gateway usually sits at the ISP head-end, so a reachable gateway does NOT mean the circuit is good. Reboot the cable modem first; if that does not restore it, open an ISP ticket to have them check the circuit path end-to-end.'.$tail;
    }

    private function ifToken(?string $port): ?string
    {
        if ($port === null || $port === '') {
            return $port;
        }
        $port = trim($port);

        return preg_match('#^[A-Za-z]+[\w./:-]*\d#', $port, $m) ? rtrim($m[0], '.:-') : $port;
    }

    /**
     * Name the root cause from the same-site dependency chain: a down underlay
     * circuit with a downstream next-hop/tunnel failure is the root; the rest are
     * symptoms. A tunnel/next-hop failure with the circuit still up points at the
     * Silver Peak / last mile instead.
     */
    private function rootCause(Site $site, ?Device $edgeDev, array $edges, ?string $firstNhId = null, $circuits = null): array
    {
        $downCircuit = ($circuits ?? $site->circuits)->firstWhere('status', 'down');

        // The appliance's OWN SNMP alarms are the authoritative, real-time WAN
        // signal — the SSH next-hop poller can lag or miss it (see edgeWanDown).
        // Pull the gateway IP a gw:<ip> alarm names, to label the down next-hop.
        $gwAlarmIp = '';
        if ($edgeDev) {
            foreach ($edgeDev->alarms as $a) {
                if (preg_match('/:gw:([0-9.]+)/i', (string) $a->alarm_id, $m)) {
                    $gwAlarmIp = $m[1];
                }
            }
        }

        $nextHopDown = $this->edgeWanDown($edgeDev);
        $downTunnels = $this->downTunnelCount($edgeDev);

        // Name the actual down WAN next-hop(s) from the polled table; the
        // device-level next_hop_ip is usually empty on multi-WAN edges.
        $downNhIps = '';
        if ($nextHopDown && $edgeDev) {
            $downNhIps = $edgeDev->nextHops
                ->where('status', 'down')
                ->pluck('ip_address')
                ->filter()
                ->implode(', ');
            // Fall back to the gateway IP the appliance named in its alarm, then to
            // the device's configured next hop.
            $downNhIps = $downNhIps !== '' ? $downNhIps : ($gwAlarmIp !== '' ? $gwAlarmIp : (string) $edgeDev->next_hop_ip);
        }

        $symptomList = [];
        if ($nextHopDown) {
            $symptomList[] = $downNhIps !== ''
                ? "next-hop {$downNhIps} unreachable"
                : 'next-hop unreachable';
        }
        if ($downTunnels > 0) {
            $symptomList[] = "{$downTunnels} SD-WAN tunnel".($downTunnels > 1 ? 's' : '').' down';
        }

        // Is the site STILL passing traffic? An up tunnel or a second live WAN
        // next-hop means the SD-WAN failed over — the site is degraded (redundancy
        // lost), not down. This is what distinguishes "1 of 2 circuits down, still
        // working" from a real outage.
        $allCircuits = $circuits ?? $site->circuits;
        $downCircuitCount = $allCircuits->where('status', 'down')->count();
        $upTunnels = $edgeDev ? $edgeDev->tunnels->where('status', 'up')->count() : 0;
        // A WAN whose IP-SLA is down ARPs its gateway ("reachable") but can't pass
        // traffic — it is NOT a working WAN, so don't let it mask a real outage when
        // every uplink is dead.
        $slaDownWans = $this->ipSlaDownWans($edgeDev);
        $upNextHops = $edgeDev ? $edgeDev->nextHops
            ->where('status', '!=', 'down')
            ->reject(fn ($nh) => in_array(strtolower((string) $nh->interface), $slaDownWans, true))
            ->count() : 0;
        $hasWorkingWan = $upTunnels > 0 || $upNextHops > 0;

        // DEGRADED (not an outage): a WAN circuit/next-hop is down but the SD-WAN is
        // still up on another path. Warn — don't cry "outage" and send a tech when
        // the site is running on its backup WAN.
        if (($downCircuit || $nextHopDown) && $hasWorkingWan && $edgeDev) {
            // Name the actual down WAN so the operator sees WHICH uplink failed —
            // the interface (wan0/wan1) from the appliance's link-down alarm, and
            // the ISP/circuit riding that interface — not just a bare next-hop IP.
            $downWan = '';
            $isLinkDown = false; // a real wanN link-down alarm (interface itself down)
            foreach ($edgeDev->alarms as $a) {
                if (preg_match('/:(wan\d)\b/i', (string) $a->alarm_id, $m)) {
                    $downWan = strtolower($m[1]);
                    $isLinkDown = true;
                    break;
                }
            }
            // Or the WAN whose IP-SLA can't reach the internet (reachable next-hop,
            // no traffic) — "Port wanN" in the IP-SLA alarm, not a ":wanN" link alarm.
            $downWan = $downWan ?: ($this->ipSlaDownWans($edgeDev)[0] ?? '');
            $wanCircuit = $downCircuit ?? ($downWan ? $allCircuits->firstWhere('wan_interface', $downWan) : null);
            if ($wanCircuit) {
                $what = trim(($downWan ? strtoupper($downWan).' — ' : '')."{$wanCircuit->isp_name} {$wanCircuit->circuit_id}");
            } elseif ($downWan) {
                $what = strtoupper($downWan);
            } else {
                $what = $downNhIps !== '' ? "next-hop {$downNhIps}" : 'a WAN path';
            }

            return [
                'active' => true,
                // A WAN going down is CRITICAL even when failover holds — the site is
                // running with no redundancy and one more failure isolates it. Loud,
                // but the summary makes clear traffic is still flowing on the backup.
                'severity' => 'critical',
                'root_type' => 'degraded',
                'root_label' => $edgeDev->name,
                'device_id' => $edgeDev->id,
                'circuit_id' => ($downCircuit ?? $wanCircuit)?->id,
                'support_phone' => $wanCircuit ? (optional($wanCircuit->ispProvider)->support_phone ?? $wanCircuit->support_phone) : null,
                'symptoms' => $symptomList,
                'summary' => "{$what} DOWN — running on backup WAN, redundancy lost",
                'action' => $this->wanDownAction($isLinkDown, true),
                'root_edge' => null,
            ];
        }

        // Root = the ISP underlay: a circuit is down AND there is no working WAN
        // left, so downstream failed. All circuits down = the site is isolated.
        if ($downCircuit && ($nextHopDown || $downTunnels > 0)) {
            $rootEdge = null;
            if ($edgeDev) {
                $to = $firstNhId ?? "ec-{$edgeDev->id}";
                $rootEdge = ['from' => "isp-{$downCircuit->id}", 'to' => $to];
            }
            $isolated = $downCircuitCount > 1 && $allCircuits->where('status', '!=', 'down')->isEmpty();
            $summary = $isolated
                ? "Site isolated — all {$downCircuitCount} WAN circuits down"
                : "ISP outage — {$downCircuit->isp_name} {$downCircuit->circuit_id} down";

            return [
                'active' => true,
                'severity' => 'critical',
                'root_type' => 'circuit',
                'root_label' => "{$downCircuit->isp_name} {$downCircuit->circuit_id}",
                'circuit_id' => $downCircuit->id,
                'support_phone' => optional($downCircuit->ispProvider)->support_phone ?? $downCircuit->support_phone,
                'symptoms' => $symptomList,
                'summary' => $summary,
                'action' => 'Open an ISP ticket to have the circuit checked; the monitored IP is unreachable.',
                'root_edge' => $rootEdge,
            ];
        }

        // A WAN is down with no failover left. TWO different faults land here, and
        // they need different remediation:
        //   - next-hop UNREACHABLE (gateway doesn't even ARP) or a wanN link-down →
        //     the local side: check the WAN port + cable to the modem/ONT first.
        //   - IP-SLA down (gateway reachable, no internet traffic) → NOT the local
        //     cable; reboot the modem, then ISP ticket for the circuit path.
        if ($nextHopDown && ! $downCircuit && $edgeDev) {
            $linkOrGwDown = $edgeDev->alarms->contains(fn ($a) => (bool) preg_match('/:gw:|:wan\d/i', (string) $a->alarm_id))
                || $edgeDev->nextHops->where('status', 'down')->isNotEmpty();
            $slaWan = $this->ipSlaDownWans($edgeDev)[0] ?? '';

            if (! $linkOrGwDown && $slaWan !== '') {
                $summary = "WAN ".strtoupper($slaWan)." can't reach the internet at {$edgeDev->name} — gateway reachable, no traffic";
            } else {
                $nhText = $downNhIps !== '' ? " — next-hop {$downNhIps} unreachable" : '';
                $summary = "WAN uplink down at {$edgeDev->name}{$nhText}";
            }

            return [
                'active' => true,
                'root_type' => 'access',
                'root_label' => $edgeDev->name,
                'device_id' => $edgeDev->id,
                'support_phone' => null,
                'symptoms' => $symptomList,
                'summary' => $summary,
                'action' => $this->wanDownAction($linkOrGwDown, false),
                'root_edge' => null,
            ];
        }

        // Next-hop reachable but SD-WAN tunnels are down → the overlay / appliance,
        // not the local access link.
        if ($downTunnels > 0 && ! $downCircuit && $edgeDev) {
            return [
                'active' => true,
                'root_type' => 'edge',
                'root_label' => $edgeDev->name,
                'device_id' => $edgeDev->id,
                'support_phone' => null,
                'symptoms' => $symptomList,
                'summary' => "SD-WAN overlay degraded at {$edgeDev->name}",
                'action' => 'The next-hop is reachable but SD-WAN tunnels are down — check the EdgeConnect overlay / Business Intent config or the far-end appliance.',
                'root_edge' => null,
            ];
        }

        // A down circuit with no edge downstream — still an ISP problem.
        if ($downCircuit) {
            return [
                'active' => true,
                'root_type' => 'circuit',
                'root_label' => "{$downCircuit->isp_name} {$downCircuit->circuit_id}",
                'circuit_id' => $downCircuit->id,
                'support_phone' => optional($downCircuit->ispProvider)->support_phone ?? $downCircuit->support_phone,
                'symptoms' => $symptomList,
                'summary' => "Circuit down — {$downCircuit->isp_name} {$downCircuit->circuit_id}",
                'action' => 'Open an ISP ticket to have the circuit checked; the monitored IP is unreachable.',
                'root_edge' => null,
            ];
        }

        // No WAN fault, but a managed access switch is unreachable (device-down):
        // that switch — and everything hanging off it — is dark. Name it, don't
        // leave the site looking healthy.
        $downSwitch = $site->devices->first(fn ($d) => in_array($d->role, ['switch', 'firewall'], true)
            && $d->alarms->contains(fn ($a) => $a->alarm_id === 'device-unreachable'));
        if ($downSwitch) {
            return [
                'active' => true,
                'severity' => 'critical',
                'root_type' => 'switch',
                'root_label' => $downSwitch->name,
                'device_id' => $downSwitch->id,
                'support_phone' => null,
                'symptoms' => ["{$downSwitch->name} unreachable"],
                'summary' => "Switch down — {$downSwitch->name} unreachable",
                'action' => 'The switch is unreachable (power, its uplink, or the switch itself). Check its uplink port and power; endpoints on it are offline.',
                'root_edge' => null,
            ];
        }

        return ['active' => false, 'summary' => 'Healthy', 'symptoms' => [], 'action' => null, 'root_edge' => null];
    }
}
