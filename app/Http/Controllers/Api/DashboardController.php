<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Models\Tunnel;
use App\Services\TunnelCorrelation;
use App\Support\TunnelAlarm;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // The dashboard is polled by every open NOC screen AND the wallboard TV
        // every 15–30s. On the single-worker web server that repeated full recompute
        // is the dominant load; an 8s cache means one computation serves all pollers
        // in that window (the payload is fleet-wide — no per-user content) while
        // staying fresher than the poll interval.
        $payload = \Illuminate\Support\Facades\Cache::remember('dashboard.payload', 8, fn () => $this->buildDashboard());

        return response()->json($payload);
    }

    /**
     * Productivity signals for the dashboard's health hero + right rail — the 24h
     * alarm trend, MTTR, and the recurring problem sites. Heavier history queries than
     * the live payload, so cached longer (60s) and served from its own endpoint rather
     * than slowing the 15–30s dashboard poll.
     */
    public function insights(): JsonResponse
    {
        $payload = \Illuminate\Support\Facades\Cache::remember('dashboard.insights', 60, function () {
            $now = now();
            $dayAgo = $now->copy()->subDay();
            $weekAgo = $now->copy()->subDays(7);

            // 24h trend — alarms raised per hour (device alarms + circuit outages),
            // bucketed in PHP so it stays DB-portable (SQLite dev / MySQL prod).
            $raised = DeviceAlarm::where('first_seen_at', '>=', $dayAgo)->pluck('first_seen_at')
                ->concat(CircuitAlert::where('started_at', '>=', $dayAgo)->pluck('started_at'));
            $buckets = array_fill(0, 24, 0);
            foreach ($raised as $ts) {
                if (! $ts) {
                    continue;
                }
                // Carbon 3 (Laravel 12) returns a SIGNED diff — abs() so a past
                // timestamp buckets correctly instead of landing out of range.
                $h = 23 - (int) floor(abs($now->diffInSeconds($ts)) / 3600);
                if ($h >= 0 && $h < 24) {
                    $buckets[$h]++;
                }
            }

            // MTTR — median minutes to clear, over the last 7 days.
            $durations = DeviceAlarm::whereNotNull('cleared_at')->where('cleared_at', '>=', $weekAgo)
                ->get(['first_seen_at', 'cleared_at'])
                ->map(fn ($a) => $a->cleared_at && $a->first_seen_at ? abs($a->cleared_at->diffInSeconds($a->first_seen_at)) : null)
                ->filter();

            // Recurring problem sites — most alarms opened in 7 days.
            $offenders = DeviceAlarm::where('first_seen_at', '>=', $weekAgo)
                ->join('devices', 'device_alarms.device_id', '=', 'devices.id')
                ->join('sites', 'devices.site_id', '=', 'sites.id')
                ->selectRaw('sites.id as site_id, sites.name as site_name, COUNT(*) as c')
                ->groupBy('sites.id', 'sites.name')
                ->orderByDesc('c')->limit(6)->get()
                ->map(fn ($r) => ['site_id' => (int) $r->site_id, 'site_name' => $r->site_name, 'count' => (int) $r->c])
                ->values();

            return [
                'trend_24h' => array_values($buckets),
                'raised_24h' => array_sum($buckets),
                'resolved_24h' => DeviceAlarm::whereNotNull('cleared_at')->where('cleared_at', '>=', $dayAgo)->count(),
                'mttr_minutes' => $durations->count() ? (int) round($durations->median() / 60) : null,
                'top_offenders' => $offenders,
                'anomalies_open' => \App\Models\Anomaly::open()->count(),
            ];
        });

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function buildDashboard(): array
    {
        $alerts = $this->activeAlerts();

        // A hub holds a tunnel to every spoke, so when a SPOKE's transport fails the hub
        // alarms too ("to_<spoke>" tunnel down). Those belong to the spoke, not the hub —
        // the same correlation the Alarms/incidents view uses. Drop the suppressed hub
        // symptoms here so the hub stops SHOWING a branch's outage (e.g. #893/#000 no
        // longer display #063's wan0 tunnels) AND the bell stops COUNTING them.
        $suppressed = (new TunnelCorrelation)->analyze()['suppressed_alarm_ids'];
        if ($suppressed !== []) {
            $suppressedSet = array_flip($suppressed);
            $alerts = array_values(array_filter(
                $alerts,
                fn (array $a) => ! isset($suppressedSet[$a['alarm_ref'] ?? -1]),
            ));
        }

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

        $siteNames = Site::pluck('name', 'id');

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

        return [
            'sites' => $sites,
            'availability' => $this->availability(),
            'traffic' => $this->traffic(),
            // Contract accountability: circuits whose service contract expires within
            // 60 days (or already has). An ops reminder, deliberately NOT in the NOC
            // alarm stream — surfaced as its own dashboard widget.
            'contracts_expiring' => Circuit::expiringWithin(60)->with('site:id,name')
                ->orderBy('contract_end_date')->get()
                ->map(fn (Circuit $c) => [
                    'id' => $c->id,
                    'circuit_id' => $c->circuit_id,
                    'isp_name' => $c->isp_name,
                    'site_name' => $c->site?->name,
                    'contract_end_date' => $c->contract_end_date?->toDateString(),
                    'days_to_expiry' => $c->daysToExpiry(),
                    'status' => $c->contractStatus(),
                ])->values(),
            // site_id is kept so the map can show a location's alarms on click;
            // site_name so the list can say WHERE without a lookup — a circuit id
            // alone ("Lumen — DSLTL18-23703944") does not tell an operator the site.
            'alerts' => collect($correlated)->map(
                fn (array $a) => collect($this->withSiteName($a, $siteNames))
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
                // Derived from the alert list (like circuits_down) so the KPI count
                // ALWAYS equals what the drill-down lists. Includes SSH-down tunnels
                // AND the appliance's SNMP 'ec:…:Tunnel' rollup (SNMP is authoritative:
                // a real tunnels-down alarm must count even when the slow SSH table is
                // stale-all-up) — each is a clickable type='tunnel' entry below.
                'tunnels_down' => collect($alerts)->where('type', 'tunnel')->count(),
                // Quality breaches are degraded-not-down; counted separately so the
                // outage KPI stays honest.
                'tunnels_degraded' => collect($alerts)->where('type', 'tunnel-quality')->count(),
                // Tunnel-down SNMP alarms are counted in the Tunnels KPI, not here.
                'active_alarms' => DeviceAlarm::whereNull('cleared_at')->get()
                    ->reject(fn ($a) => $this->isTunnelAlarm((string) $a->alarm_id))->count(),
                // Raw pre-grouping signal count — stays consistent with the per-type KPI
                // cards (circuits_down / tunnels_down / …), each a raw signal count.
                'active_alerts' => count($alerts),
                // The BELL badge counts the CORRELATED incidents actually shown in the
                // list, not the raw signals. Otherwise the bell reads a big number (e.g.
                // 18 = 3 alarms + 15 hub tunnels) that never matches the dashboard's
                // grouped list and barely moves when one alarm is cleared.
                'active_incidents' => collect($correlated)->count(),
            ],
        ];
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
                'alerts' => fn ($q) => $q->whereNull('ended_at')->with(['acknowledgedBy:id,name', 'dispatchBy:id,name'])->latest('started_at'),
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
                // Field dispatch: when a tech was sent to this outage (day/time shown on the wall).
                'dispatch_at' => optional($open)->dispatch_at,
                'dispatched_by' => optional(optional($open)->dispatchBy)->name,
                'device_id' => null,
                'circuit_id' => $circuit->id,
                'site_id' => $circuit->site_id,
                // In-place NOC actions, same as the By-ISP view: ack/clear via the circuit,
                // "mute" = pause monitoring (admin).
                'actions' => [
                    'ack' => ['url' => "/api/circuits/{$circuit->id}/acknowledge"],
                    'clear' => ['url' => "/api/circuits/{$circuit->id}/clear"],
                    'mute' => ['url' => "/api/circuits/{$circuit->id}/monitoring", 'body' => ['enabled' => false], 'label' => 'Pause circuit', 'admin' => true],
                ],
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
                // X.733: a down interface is only service-affecting (critical) when it's
                // an UPLINK/trunk. A plain access port (a laptop unplugged for a meeting)
                // is a WARNING. The poller already computed this per-alert — honour it
                // instead of blanket-reddening every port-down on the NOC wall.
                'severity' => optional($open)->severity ?? 'warning',
                'started_at' => optional($open)->started_at ?? $interface->last_polled_at,
                'ticket_number' => null,
                'device_id' => $interface->device_id,
                'device_name' => optional($interface->device)->name,
                // Carried so the dashboard can jump straight to THIS port on the device page.
                'if_name' => $interface->if_name,
                'interface_id' => $interface->id,
                'acknowledged_at' => optional($open)->acknowledged_at,
                'circuit_id' => null,
                'site_id' => optional($interface->device)->site_id,
                // Ack/clear via the interface alert; "mute" = suppress the port (admin).
                'actions' => [
                    'ack' => $open ? ['url' => "/api/interface-alerts/{$open->id}/acknowledge"] : null,
                    'clear' => $open ? ['url' => "/api/interface-alerts/{$open->id}/clear"] : null,
                    'mute' => ['url' => "/api/interfaces/{$interface->id}/suppress", 'label' => 'Mute port', 'admin' => true],
                ],
            ];
        }

        $downTunnels = Tunnel::where('status', 'down')
            ->with(['device.tunnels', 'device.nextHops', 'latestAlert', 'alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->get();
        foreach ($downTunnels as $tunnel) {
            $open = $tunnel->alerts->first();
            // Stuck-bell fix: a NOC hand-cleared this tunnel while it is still
            // oper-down (peer decommissioned, or a false down handled manually) —
            // suppress it until a real flap reopens a fresh alert, exactly like
            // circuits (above). Without this, a manually-cleared tunnel stayed in
            // the bell/tunnels_down count forever with no clearable alert.
            if (! $open && optional($tunnel->latestAlert)->cleared_manually) {
                continue;
            }
            $alerts[] = [
                'key' => "tunnel-{$tunnel->id}",
                'type' => 'tunnel',
                'title' => optional($tunnel->device)->name.' · '.$tunnel->tunnel_name,
                'subtitle' => 'Tunnel down',
                'detail' => 'SD-WAN overlay',
                // X.733: critical only when the site is isolated. A single tunnel down
                // while another path survives is DEGRADED → warning (SD-WAN rerouted).
                'severity' => ($tunnel->device && $tunnel->device->hasWorkingWan()) ? 'warning' : 'critical',
                'started_at' => optional($open)->started_at ?? $tunnel->last_checked_at,
                'ticket_number' => null,
                'device_id' => $tunnel->device_id,
                'device_name' => optional($tunnel->device)->name,
                'circuit_id' => null,
                'site_id' => optional($tunnel->device)->site_id,
            ];
        }

        // EdgeConnect tunnel-down SNMP alarms — the 'ec:…:Tunnel' rollup AND every
        // per-tunnel 'ec:…:to_<peer>' alarm — are TUNNEL signals, not generic
        // alarms. Classify them ALL as type='tunnel' so a hub with one down tunnel
        // and a hub with three read the same way (previously the per-tunnel alarms
        // were generic 'alarm's, so 3-on-a-device collapsed into a "Device alarms"
        // incident while a 1-tunnel hub showed its tunnel plainly). Skip devices the
        // SSH tunnel table already covers, so a tunnel is counted once.
        $sshTunnelDeviceIds = $downTunnels->pluck('device_id')->filter()->unique()->all();
        $allOpenAlarms = DeviceAlarm::whereNull('cleared_at')->with(['device.tunnels', 'device.nextHops', 'acknowledgedBy:id,name'])->get();
        [$tunnelAlarms, $genericAlarms] = $allOpenAlarms->partition(fn ($a) => $this->isTunnelAlarm((string) $a->alarm_id));

        foreach ($tunnelAlarms as $alarm) {
            if (in_array($alarm->device_id, $sshTunnelDeviceIds, true)) {
                continue; // the SSH table already lists this appliance's tunnels
            }
            $peer = $this->tunnelAlarmPeer((string) $alarm->alarm_id);
            $isQuality = $this->isQualityAlarm($alarm->description);
            $alerts[] = [
                'key' => "tunnel-snmp-{$alarm->id}",
                // The row this alert came from, so the operator can select it for a
                // bulk clear. Only alerts backed by a DeviceAlarm carry one.
                'alarm_ref' => $alarm->id,
                // A separate category so quality breaches can be filtered, counted
                // and cleared in bulk without touching real outages.
                'type' => $isQuality ? 'tunnel-quality' : 'tunnel',
                'title' => optional($alarm->device)->name.' · '.($peer !== '' ? "tunnel to {$peer}" : 'tunnels down'),
                'subtitle' => $isQuality ? 'Link quality threshold' : 'Tunnel down',
                'detail' => $alarm->description ?: 'SD-WAN tunnel down (SNMP).',
                // Quality breaches keep the poller's stored severity. A tunnel-DOWN
                // is X.733-graded by WAN health: warning if the appliance still has a
                // working path (degraded/rerouted), critical only if it's isolated.
                'severity' => $isQuality
                    ? ($alarm->severity ?: 'warning')
                    : (($alarm->device && $alarm->device->hasWorkingWan()) ? 'warning' : 'critical'),
                'started_at' => $alarm->first_seen_at,
                'ticket_number' => $alarm->ticket_number,
                'device_id' => $alarm->device_id,
                'device_name' => optional($alarm->device)->name,
                'circuit_id' => null,
                'site_id' => optional($alarm->device)->site_id,
                'acknowledged_at' => $alarm->acknowledged_at,
                'actions' => [
                    'ack' => ['url' => "/api/alarms/{$alarm->id}/acknowledge"],
                    'clear' => ['url' => "/api/alarms/{$alarm->id}/clear"],
                    'mute' => null,
                ],
            ];
        }

        // Everything else stays a generic Orchestrator alarm (tunnel alarms above
        // are counted in the Tunnels KPI, not here).
        foreach ($genericAlarms as $alarm) {
            // A device that has stopped answering ICMP is DOWN — the most severe,
            // most actionable state. Surface it as such (not a vague "Orchestrator
            // alarm"), always critical, and tag it so same-site downs correlate into
            // one site-outage incident rather than a wall of separate criticals.
            $isDown = (string) $alarm->alarm_id === 'device-unreachable';
            $alerts[] = [
                'key' => "alarm-{$alarm->id}",
                'alarm_ref' => $alarm->id,
                'type' => 'alarm',
                'is_device_down' => $isDown,
                'title' => optional($alarm->device)->name.($isDown ? ' — DOWN' : ' — '.$alarm->alarm_id),
                'subtitle' => $isDown ? 'Device unreachable (ICMP) — DOWN' : 'Orchestrator alarm',
                'detail' => $alarm->description,
                'severity' => $isDown ? 'critical' : ($alarm->severity === 'critical' ? 'critical' : 'warning'),
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
                'actions' => [
                    'ack' => ['url' => "/api/alarms/{$alarm->id}/acknowledge"],
                    'clear' => ['url' => "/api/alarms/{$alarm->id}/clear"],
                    'mute' => null,
                ],
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

        $deviceLevel = [];
        foreach ($byDevice as $members) {
            $deviceLevel[] = count($members) === 1 ? $members[0] : $this->makeIncident($members);
        }

        // Site-level correlation: when 2+ DEVICES at one site are all unreachable,
        // that is a single site outage (the site went dark from the WAN edge / power
        // — the SD-WAN appliance and the switch behind it drop together), not two
        // unrelated criticals. Group them so the NOC sees one actionable incident.
        // "Down" = a standalone unreachable alert, OR a device incident whose
        // signals include the unreachable one (the box went down and took its
        // interfaces/tunnels with it).
        $isDown = fn ($a) => ($a['is_device_down'] ?? false) === true
            || (($a['type'] ?? '') === 'incident'
                && collect($a['members'] ?? [])->contains(fn ($m) => ($m['is_device_down'] ?? false) === true));
        [$downs, $others] = collect($deviceLevel)->partition($isDown);
        foreach ($others as $o) {
            $out[] = $o;
        }
        foreach ($downs->groupBy('site_id') as $siteId => $group) {
            if ($siteId !== '' && $siteId !== null && $group->count() >= 2) {
                $out[] = $this->makeSiteOutage($group->values()->all());
            } else {
                foreach ($group as $g) {
                    $out[] = $g;
                }
            }
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
     * One critical incident for a site where multiple devices are unreachable at
     * once. The SD-WAN appliance (if among them) is the likely root — a site loses
     * reachability from its WAN edge, taking the switch behind it with it.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function makeSiteOutage(array $members): array
    {
        $siteId = $members[0]['site_id'] ?? null;
        $names = array_filter(array_column($members, 'device_name'));

        $starts = array_values(array_filter(array_column($members, 'started_at')));
        sort($starts);

        return [
            'key' => "site-outage-{$siteId}",
            'type' => 'incident',
            'is_site_outage' => true,
            'title' => count($members).' devices DOWN',
            'subtitle' => 'Site outage — devices unreachable (check WAN edge / power)',
            'detail' => count($members).' devices unreachable: '.implode(', ', $names),
            'severity' => 'critical',
            'started_at' => $starts[0] ?? null,
            'device_id' => null,
            'circuit_id' => null,
            'site_id' => $siteId,
            'member_count' => count($members),
            'members' => collect($members)->sortByDesc('started_at')->values()->all(),
        ];
    }

    /**
     * Stamp an alert (and any correlated members) with its site's name.
     *
     * @param  array<string, mixed>  $alert
     * @param  \Illuminate\Support\Collection<int, string>  $siteNames
     * @return array<string, mixed>
     */
    private function withSiteName(array $alert, $siteNames): array
    {
        $alert['site_name'] = $alert['site_id'] !== null ? ($siteNames[$alert['site_id']] ?? null) : null;

        if (! empty($alert['members'])) {
            $alert['members'] = array_map(fn (array $m) => $this->withSiteName($m, $siteNames), $alert['members']);
        }

        return $alert;
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
        // Summing 24h of interface_metric_history is a heavy aggregate over a huge
        // table, and the dashboard is polled continuously by every open tab's
        // notification bell — so cache the fleet-wide total briefly. A 60s-stale
        // 24h traffic number is fine, and it takes the query off the hot path.
        return \Illuminate\Support\Facades\Cache::remember('dashboard.traffic', 60, function () {
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
        });
    }

    /**
     * Is this DeviceAlarm a SD-WAN tunnel-down signal? The EdgeConnect 'Tunnel'
     * rollup (ec:<t>:Tunnel), a per-tunnel alarm (ec:<t>:to_<peer>…), or a
     * 'tunnel_down' named alarm — all belong in the Tunnels bucket, not Alarms.
     */
    /**
     * Is this tunnel alarm a link-quality breach rather than an outage?
     *
     * The appliance raises alarms against a tunnel for two very different reasons.
     * One is the overlay being down, which is service-affecting. The other is a
     * sampled latency, jitter or loss figure crossing its configured threshold,
     * which on a bulk or management tunnel usually reflects orchestrator or cloud
     * conditions upstream and passes traffic throughout.
     *
     * Both arrive as 'ec:<typeId>:to_<peer>', so the alarm id alone cannot tell
     * them apart — the description is what distinguishes them. Presenting the
     * second as "Tunnel down" and forcing it to critical buried the real outages:
     * on the reference fleet every open alarm was a threshold breach, each stored
     * correctly as a warning and each displayed as a critical tunnel outage.
     */
    private function isQualityAlarm(?string $description): bool
    {
        return TunnelAlarm::isQuality($description);
    }

    private function isTunnelAlarm(string $alarmId): bool
    {
        return TunnelAlarm::isTunnel($alarmId);
    }

    /** The peer/hostname a per-tunnel alarm targets: ec:<t>:to_<peer>_<wan>-<wan> → <peer>. */
    private function tunnelAlarmPeer(string $alarmId): string
    {
        if (! preg_match('/^ec:\d+:to_(.+)$/i', $alarmId, $m)) {
            return '';
        }
        $rest = $m[1];                     // "SC0005-SC186_DIA1-Broadband1"
        $cut = strrpos($rest, '_');        // drop the trailing "_<localWan>-<remoteWan>"

        return $cut === false ? $rest : substr($rest, 0, $cut);
    }
}
