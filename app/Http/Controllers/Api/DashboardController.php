<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Models\Tunnel;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $alerts = $this->activeAlerts();
        // Raw signals drive the KPI counts (consistent with per-type cards); the
        // list shown to the NOC is correlated into incidents.
        $correlated = $this->correlate($alerts);

        // Roll active-alert counts up to each alert's owning site.
        $alertsBySite = [];
        foreach ($alerts as $alert) {
            if ($alert['site_id'] !== null) {
                $alertsBySite[$alert['site_id']] = ($alertsBySite[$alert['site_id']] ?? 0) + 1;
            }
        }

        $sites = Site::query()
            ->withCount(['devices', 'circuits'])
            ->orderBy('name')
            ->get()
            ->map(function (Site $site) use ($alertsBySite) {
                $activeAlertCount = $alertsBySite[$site->id] ?? 0;

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'latitude' => $site->latitude,
                    'longitude' => $site->longitude,
                    'device_count' => $site->devices_count,
                    'circuit_count' => $site->circuits_count,
                    'active_alert_count' => $activeAlertCount,
                    'health' => $activeAlertCount > 0 ? 'critical' : 'good',
                ];
            })
            ->values();

        return response()->json([
            'sites' => $sites,
            'availability' => $this->availability(),
            'traffic' => $this->traffic(),
            // site_id is kept so the map can show a location's alarms on click.
            'alerts' => collect($correlated)->map(
                fn (array $a) => collect($a)
                    ->put('previous_ticket_number', $a['previous_ticket_number'] ?? null)
                    ->put('support_phone', $a['support_phone'] ?? null)
            )->values(),
            'counts' => [
                'sites' => Site::count(),
                'devices' => Device::count(),
                // Derived from the alert list so a hand-cleared outage drops out of
                // both the KPI and the list together.
                'circuits_down' => collect($alerts)->where('type', 'circuit')->count(),
                // Admin-down (disabled/unused) ports are excluded — an intentional
                // shutdown is not an outage.
                'interfaces_down' => DeviceInterface::where('status', 'down')->where('admin_status', 'up')
                    ->where('alarm_suppressed', false)
                    ->whereHas('alerts', fn ($q) => $q->whereNull('ended_at'))->count(),
                // Down tunnels the card can actually LIST when clicked = the
                // SSH-polled tunnel table (each is an enumerable, clickable entry
                // below). A SNMP-only 'ec:…:Tunnel' rollup has no per-tunnel detail
                // to show, so it must NOT inflate this count (it would read "1" with
                // an empty drill-down) — it's still surfaced in the alarms KPI and
                // topology as its device's alarm/incident.
                'tunnels_down' => Tunnel::where('status', 'down')->count(),
                'active_alarms' => DeviceAlarm::whereNull('cleared_at')->count(),
                'active_alerts' => count($alerts),
            ],
        ]);
    }

    /**
     * A unified, newest-first list of everything currently in an alert state.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeAlerts(): array
    {
        $alerts = [];

        $downCircuits = Circuit::where('status', 'down')
            ->where('monitoring_enabled', true) // circuits in maintenance don't alarm
            ->with([
                'ispProvider',
                'alerts' => fn ($q) => $q->whereNull('ended_at')->with('acknowledgedBy:id,name')->latest('started_at'),
                'latestAlert.clearedBy:id,name',
            ])
            ->get();
        foreach ($downCircuits as $circuit) {
            $open = $circuit->alerts->first();
            // A NOC hand-cleared this outage (false positive / maintenance) while
            // the circuit is still physically down: suppress it until a real flap
            // reopens a fresh alert. Same "no-resurrect" rule device alarms use.
            if (! $open && optional($circuit->latestAlert)->cleared_manually) {
                continue;
            }
            // Prefer the ISP provider's shared support line; fall back to any
            // legacy per-circuit number.
            $supportPhone = optional($circuit->ispProvider)->support_phone ?? $circuit->support_phone;

            // The most recent ISP ticket previously logged for this circuit
            // (from an earlier outage), so a recurring issue can reference or
            // reopen it rather than losing the history when the alert cleared.
            $previousTicket = $circuit->alerts()
                ->whereNotNull('ticket_number')
                ->when($open, fn ($q) => $q->where('id', '!=', $open->id))
                ->latest('started_at')
                ->value('ticket_number');

            $alerts[] = [
                'key' => "circuit-{$circuit->id}",
                'type' => 'circuit',
                'title' => "{$circuit->isp_name} — {$circuit->circuit_id}",
                'subtitle' => 'Circuit down',
                'detail' => "Monitored IP {$circuit->monitored_ip}",
                'severity' => 'critical',
                'started_at' => optional($open)->started_at ?? $circuit->last_checked_at,
                'ticket_number' => optional($open)->ticket_number,
                'previous_ticket_number' => $previousTicket,
                'support_phone' => $supportPhone,
                'acknowledged_at' => optional($open)->acknowledged_at,
                'acknowledged_by' => optional(optional($open)->acknowledgedBy)->name,
                'device_id' => null,
                'circuit_id' => $circuit->id,
                'site_id' => $circuit->site_id,
            ];
        }

        // Only interfaces with an ACTIVE (uncleared) alert are alarming. Requiring
        // an open alert means: a manually-cleared alert drops off the dashboard even
        // though the port is still physically down (the NOC handled it), and logical
        // sub-units (ge-0/0/28.0) — which never raise their own alert — are excluded
        // so they don't double-count the physical parent.
        $downInterfaces = DeviceInterface::where('status', 'down')->where('admin_status', 'up')
            ->where('alarm_suppressed', false) // false-alarm/unused ports muted at onboarding
            ->whereHas('alerts', fn ($q) => $q->whereNull('ended_at'))
            ->with(['device', 'alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->get();
        foreach ($downInterfaces as $interface) {
            $open = $interface->alerts->first();
            $alerts[] = [
                'key' => "interface-{$interface->id}",
                'type' => 'interface',
                'title' => optional($interface->device)->name.' · '.$interface->if_name,
                'subtitle' => 'Interface down',
                'detail' => optional($interface->device)->vendor.' '.optional($interface->device)->model,
                'severity' => 'critical',
                'started_at' => optional($open)->started_at ?? $interface->last_polled_at,
                'ticket_number' => null,
                'device_id' => $interface->device_id,
                'device_name' => optional($interface->device)->name,
                'circuit_id' => null,
                'site_id' => optional($interface->device)->site_id,
            ];
        }

        $downTunnels = Tunnel::where('status', 'down')
            ->with(['device', 'alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->get();
        foreach ($downTunnels as $tunnel) {
            $open = $tunnel->alerts->first();
            $alerts[] = [
                'key' => "tunnel-{$tunnel->id}",
                'type' => 'tunnel',
                'title' => optional($tunnel->device)->name.' · '.$tunnel->tunnel_name,
                'subtitle' => 'Tunnel down',
                'detail' => 'SD-WAN overlay',
                'severity' => 'critical',
                'started_at' => optional($open)->started_at ?? $tunnel->last_checked_at,
                'ticket_number' => null,
                'device_id' => $tunnel->device_id,
                'device_name' => optional($tunnel->device)->name,
                'circuit_id' => null,
                'site_id' => optional($tunnel->device)->site_id,
            ];
        }

        $activeAlarms = DeviceAlarm::whereNull('cleared_at')->with(['device', 'acknowledgedBy:id,name'])->get();
        foreach ($activeAlarms as $alarm) {
            $alerts[] = [
                'key' => "alarm-{$alarm->id}",
                'type' => 'alarm',
                'title' => optional($alarm->device)->name.' — '.$alarm->alarm_id,
                'subtitle' => 'Orchestrator alarm',
                'detail' => $alarm->description,
                'severity' => $alarm->severity === 'critical' ? 'critical' : 'warning',
                'started_at' => $alarm->first_seen_at,
                'ticket_number' => $alarm->ticket_number,
                'device_id' => $alarm->device_id,
                'device_name' => optional($alarm->device)->name,
                'device_ip' => optional($alarm->device)->ip_address,
                'event_id' => $alarm->alarm_id,
                'alarm_db_id' => $alarm->id,
                'acknowledged_at' => $alarm->acknowledged_at,
                'acknowledged_by' => optional($alarm->acknowledgedBy)->name,
                'circuit_id' => null,
                'site_id' => optional($alarm->device)->site_id,
            ];
        }

        $openNextHops = NextHopAlert::whereNull('ended_at')->with(['device', 'nextHop'])->get();
        foreach ($openNextHops as $nextHop) {
            $nh = $nextHop->nextHop;
            $ip = optional($nh)->ip_address ?? optional($nextHop->device)->next_hop_ip;
            $intf = optional($nh)->interface ? " ({$nh->interface})" : '';
            // Critical only if every WAN next-hop is down; warning if another is up.
            $othersUp = $nextHop->device_id
                ? \App\Models\DeviceNextHop::where('device_id', $nextHop->device_id)
                    ->when($nh, fn ($q) => $q->where('id', '!=', $nh->id))
                    ->where('status', 'up')->exists()
                : false;

            $alerts[] = [
                'key' => "next_hop-{$nextHop->id}",
                'type' => 'next_hop',
                'title' => optional($nextHop->device)->name.' — next-hop'.$intf,
                'subtitle' => 'Next-hop unreachable',
                'detail' => "Gateway {$ip}{$intf}",
                'severity' => $othersUp ? 'warning' : 'critical',
                'started_at' => $nextHop->started_at,
                'ticket_number' => null,
                'device_id' => $nextHop->device_id,
                'device_name' => optional($nextHop->device)->name,
                'circuit_id' => null,
                'site_id' => optional($nextHop->device)->site_id,
            ];
        }

        usort($alerts, fn ($a, $b) => (string) $b['started_at'] <=> (string) $a['started_at']);

        return $alerts;
    }

    /**
     * Roll the raw signals into incidents so a NOC sees one actionable item per
     * outage, not a wall of symptoms. A device outage lights up several signals at
     * once (interface + next-hop + tunnel + Orchestrator alarms) that are all one
     * problem — group them by device; the drill-down keeps every member with its
     * own detail, ticket and actions. Circuits (no device) and lone signals stay
     * standalone.
     *
     * @param  array<int, array<string, mixed>>  $alerts
     * @return array<int, array<string, mixed>>
     */
    private function correlate(array $alerts): array
    {
        $byDevice = [];
        $out = [];
        foreach ($alerts as $a) {
            if ($a['device_id']) {
                $byDevice[$a['device_id']][] = $a;
            } else {
                $out[] = $a; // circuits etc. — nothing to correlate them with
            }
        }

        foreach ($byDevice as $members) {
            $out[] = count($members) === 1 ? $members[0] : $this->makeIncident($members);
        }

        // Critical first, then most recent onset.
        usort($out, function ($a, $b) {
            $sev = fn ($x) => ($x['severity'] ?? 'warning') === 'critical' ? 0 : 1;

            return $sev($a) <=> $sev($b) ?: (string) $b['started_at'] <=> (string) $a['started_at'];
        });

        return $out;
    }

    /**
     * Build one correlated incident from several signals on the same device.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function makeIncident(array $members): array
    {
        $device = $members[0]['device_name'] ?? 'Device';
        $deviceId = $members[0]['device_id'];
        $siteId = $members[0]['site_id'] ?? null;

        $types = array_column($members, 'type');
        $severity = in_array('critical', array_column($members, 'severity'), true) ? 'critical' : 'warning';

        // Incident onset = the earliest member.
        $starts = array_values(array_filter(array_column($members, 'started_at')));
        sort($starts);
        $startedAt = $starts[0] ?? null;

        // Root headline from the causal chain: a down WAN next-hop points at the
        // local access link (check interface/cable, then ISP); tunnels-only points
        // at the overlay; otherwise it's just device alarms.
        if (in_array('next_hop', $types, true)) {
            $subtitle = 'WAN uplink down — check interface & cable, then ISP ticket';
        } elseif (in_array('interface', $types, true)) {
            $subtitle = 'Interface down';
        } elseif (in_array('tunnel', $types, true)) {
            $subtitle = 'SD-WAN overlay degraded';
        } else {
            $subtitle = 'Device alarms';
        }

        // Human breakdown of what's firing, e.g. "1 interface · 1 next-hop · 3 Orchestrator".
        $labels = ['interface' => 'interface', 'next_hop' => 'next-hop', 'tunnel' => 'tunnel', 'alarm' => 'Orchestrator'];
        $counts = array_count_values($types);
        $parts = [];
        foreach ($counts as $t => $n) {
            $parts[] = "{$n} ".($labels[$t] ?? $t).($n > 1 && $t !== 'alarm' ? 's' : '');
        }

        return [
            'key' => "incident-device-{$deviceId}",
            'type' => 'incident',
            'title' => $device,
            'subtitle' => $subtitle,
            'detail' => count($members).' correlated signals: '.implode(' · ', $parts),
            'severity' => $severity,
            'started_at' => $startedAt,
            'device_id' => $deviceId,
            'circuit_id' => null,
            'site_id' => $siteId,
            'member_count' => count($members),
            // Symptoms newest-first inside the drill-down; each keeps its actions.
            'members' => collect($members)->sortByDesc('started_at')->values()->all(),
        ];
    }

    /**
     * The worst-performing devices/circuits, so the "what's broken" list leads with
     * anything down and fills out with healthy entries only if room remains.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availability(): array
    {
        $rows = [];

        foreach (Circuit::orderByRaw("status = 'down' desc")->orderBy('isp_name')->limit(6)->get() as $circuit) {
            $rows[] = [
                'key' => "circuit-{$circuit->id}",
                'type' => 'circuit',
                'name' => "{$circuit->isp_name} — {$circuit->circuit_id}",
                'device_name' => null,
                'status' => $circuit->status,
                'route' => '/circuits',
            ];
        }

        // Exclude logical sub-units (ge-0/0/28.0) — they mirror their physical
        // parent and would just double the list.
        foreach (DeviceInterface::with('device')->where('admin_status', 'up')->where('alarm_suppressed', false)->where('if_name', 'not like', '%.%')->orderByRaw("status = 'down' desc")->orderBy('if_name')->limit(6)->get() as $interface) {
            $rows[] = [
                'key' => "interface-{$interface->id}",
                'type' => 'interface',
                'name' => $interface->if_name,
                'device_name' => optional($interface->device)->name,
                'status' => $interface->status,
                'route' => $interface->device_id ? "/devices/{$interface->device_id}" : '/devices',
            ];
        }

        foreach (Tunnel::with('device')->orderByRaw("status = 'down' desc")->orderBy('tunnel_name')->limit(4)->get() as $tunnel) {
            $rows[] = [
                'key' => "tunnel-{$tunnel->id}",
                'type' => 'tunnel',
                'name' => $tunnel->tunnel_name,
                'device_name' => optional($tunnel->device)->name,
                'status' => $tunnel->status,
                'route' => $tunnel->device_id ? "/devices/{$tunnel->device_id}" : '/devices',
            ];
        }

        // Down first, then alphabetical within each, capped so the widget stays scannable.
        usort($rows, function ($a, $b) {
            $aDown = $a['status'] === 'down' ? 0 : 1;
            $bDown = $b['status'] === 'down' ? 0 : 1;

            return $aDown <=> $bDown ?: strcmp($a['name'], $b['name']);
        });

        return array_slice($rows, 0, 8);
    }

    /**
     * 24-hour fleet-wide traffic totals plus a per-timestamp aggregated series.
     *
     * @return array<string, mixed>
     */
    private function traffic(): array
    {
        // DB-level aggregate only — loading every 24h metric row into PHP
        // exhausted memory on large fleets (500 on the dashboard). The per-point
        // series is gone (the Traffic Overview chart was removed).
        $agg = InterfaceMetricHistory::where('recorded_at', '>=', now()->subHours(24))
            ->selectRaw('COALESCE(SUM(in_octets_delta), 0) AS in_total')
            ->selectRaw('COALESCE(SUM(out_octets_delta), 0) AS out_total')
            ->selectRaw('COALESCE(SUM(in_discards_delta) + SUM(out_discards_delta), 0) AS discards_total')
            ->first();

        return [
            'in_total' => (int) ($agg->in_total ?? 0),
            'out_total' => (int) ($agg->out_total ?? 0),
            'discards_total' => (int) ($agg->discards_total ?? 0),
            'series' => [],
        ];
    }
}
