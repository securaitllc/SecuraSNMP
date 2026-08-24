<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\InterfaceAlert;
use App\Models\LldpNeighbor;
use App\Models\MacAddress;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Every active alert grouped by site → ISP circuit, so the NOC records one ISP
 * ticket + dispatch per provider instead of per-alarm. Folds in all three alert
 * kinds so grouped mode never hides one:
 *   - EdgeConnect device alarms (tunnel/next-hop/IP-SLA)  → circuit via resolver
 *   - circuit ping outages                                → that circuit (down)
 *   - interface (access/uplink) alerts                    → site bucket
 * A ping-up circuit still carrying alarms is "degraded"; a ping/link-down one is
 * "down". Tunnel rollup, NTP, chassis and interface alerts fall to a per-site
 * bucket labelled by cause.
 */
class AlarmGroupingService
{
    public function __construct(private AlarmCircuitResolver $resolver = new AlarmCircuitResolver) {}

    /** Latest LLDP neighbour keyed by "{device_id}:{lower(local_port)}" — what a down port last had connected. */
    private array $neighborMap = [];

    /** Learned MACs keyed by device_interface_id: ['list'=>[{mac,vendor}], 'more'=>int]. */
    private array $macMap = [];

    public function grouped(?int $siteId = null): array
    {
        $deviceAlarms = DeviceAlarm::query()->whereNull('cleared_at')
            ->with('device:id,name,site_id,role')
            ->when($siteId, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('site_id', $siteId)))
            ->orderByDesc('first_seen_at')->get()
            ->filter(fn ($a) => $a->device && $a->device->site_id);

        // Cross-site tunnel symptoms belong to the failing end: a hub's "to_<spoke>" alarm
        // when the spoke's transport is down is the spoke's outage, not the hub's. Drop the
        // suppressed hub symptoms so the grouped ("By ISP") view — on the dashboard AND the
        // Alarms page — stops listing e.g. #063's wan0 tunnels under #893/#000. Same
        // suppression the incidents/dashboard views use.
        $suppressed = array_flip((new TunnelCorrelation)->analyze()['suppressed_alarm_ids']);
        if ($suppressed !== []) {
            $deviceAlarms = $deviceAlarms->reject(fn ($a) => isset($suppressed[$a->id]));
        }

        $circuitAlerts = CircuitAlert::query()->whereNull('ended_at')
            ->with(['circuit', 'dispatchBy:id,name'])
            ->when($siteId, fn ($q) => $q->whereHas('circuit', fn ($c) => $c->where('site_id', $siteId)))
            ->get()->filter(fn ($a) => $a->circuit && $a->circuit->site_id);

        // M2 count consistency: an OPEN interface alert only counts as active when the
        // interface is genuinely down (oper-down, admin-up, not false-alarm-suppressed) —
        // the SAME gate the dashboard/bell uses. Without this, a suppressed or admin-down
        // port whose alert lingered open inflated the Alarms page total above the bell.
        $ifaceAlerts = InterfaceAlert::query()->whereNull('ended_at')
            ->whereHas('deviceInterface', fn ($i) => $i->where('status', 'down')->where('admin_status', 'up')->where('alarm_suppressed', false))
            ->with(['deviceInterface:id,if_name,device_id,status,admin_status,alarm_suppressed', 'deviceInterface.device:id,name,site_id'])
            ->when($siteId, fn ($q) => $q->whereHas('deviceInterface.device', fn ($d) => $d->where('site_id', $siteId)))
            ->get()->filter(fn ($a) => optional(optional($a->deviceInterface)->device)->site_id);

        if ($deviceAlarms->isEmpty() && $circuitAlerts->isEmpty() && $ifaceAlerts->isEmpty()) {
            return [];
        }

        $siteIds = collect()
            ->merge($deviceAlarms->pluck('device.site_id'))
            ->merge($circuitAlerts->pluck('circuit.site_id'))
            ->merge($ifaceAlerts->map(fn ($a) => $a->deviceInterface->device->site_id))
            ->unique()->values()->all();

        $deviceIds = $deviceAlarms->pluck('device_id')->unique()->values()->all();
        $circuitsBySite = Circuit::whereIn('site_id', $siteIds)->get()->groupBy('site_id');
        $nextHopsByDevice = DeviceNextHop::whereIn('device_id', $deviceIds)->get()->groupBy('device_id');
        $siteNames = Site::whereIn('id', $siteIds)->pluck('name', 'id');
        $openByCircuit = $circuitAlerts->keyBy('circuit_id');

        // What each downed port last had connected (LLDP retains neighbours after they drop).
        $ifDeviceIds = $ifaceAlerts->map(fn ($a) => optional(optional($a->deviceInterface)->device)->id)->filter()->unique()->all();
        $this->neighborMap = $ifDeviceIds === [] ? [] : LldpNeighbor::whereIn('device_id', $ifDeviceIds)
            ->orderByDesc('last_seen_at')->get()
            ->reduce(function (array $map, LldpNeighbor $n) {
                $key = $n->device_id.':'.strtolower((string) $n->local_port);
                $map[$key] ??= $n; // first = most recent (ordered desc)
                return $map;
            }, []);

        // Last learned MACs (+ vendor) per downed port — top 4 with an overflow count.
        $ifaceIds = $ifaceAlerts->map(fn ($a) => optional($a->deviceInterface)->id)->filter()->unique()->all();
        $this->macMap = $ifaceIds === [] ? [] : MacAddress::whereIn('device_interface_id', $ifaceIds)
            ->orderByDesc('last_seen_at')->get()->groupBy('device_interface_id')
            // unique('mac') first: a MAC that was decoded under more than one VLAN label
            // over time leaves forked rows, which would otherwise repeat the same MAC in
            // the alarm row and shove the ack/clear/mute buttons out of frame.
            ->map(function ($g) {
                $g = $g->unique('mac')->values();

                return [
                    'list' => $g->take(4)->map(fn ($m) => ['mac' => $m->mac, 'vendor' => $m->oui_vendor])->values()->all(),
                    'more' => max(0, $g->count() - 4),
                ];
            })->all();

        $daBySite = $deviceAlarms->groupBy('device.site_id');
        $caBySite = $circuitAlerts->groupBy('circuit.site_id');
        $iaBySite = $ifaceAlerts->groupBy(fn ($a) => $a->deviceInterface->device->site_id);

        $out = [];
        foreach ($siteIds as $sid) {
            $circuits = $circuitsBySite->get($sid) ?? collect();
            $byCircuit = [];   // cid => ['circuit'=>Circuit, 'alarms'=>[], 'down'=>bool]
            $bucket = [];

            foreach ($daBySite->get($sid) ?? [] as $a) {
                $nh = $nextHopsByDevice->get($a->device_id) ?? collect();
                $c = $this->resolver->resolve($a->alarm_id, (string) $a->description, $circuits, $nh);
                $row = $this->fromDeviceAlarm($a);
                if ($c) {
                    $byCircuit[$c->id]['circuit'] = $c;
                    $byCircuit[$c->id]['alarms'][] = $row;
                    $byCircuit[$c->id]['down'] = ($byCircuit[$c->id]['down'] ?? false) || $this->isLinkDown((string) $a->description);
                }
                else { $bucket[] = $row; }
            }

            // Circuit ping outages → force their circuit into a Down group.
            foreach ($caBySite->get($sid) ?? [] as $ca) {
                $c = $ca->circuit;
                $byCircuit[$c->id]['circuit'] = $c;
                $byCircuit[$c->id]['alarms'][] = $this->fromCircuitAlert($ca);
                $byCircuit[$c->id]['down'] = true;
            }

            // Interface (access/uplink) alerts are not ISP-specific → site bucket.
            foreach ($iaBySite->get($sid) ?? [] as $ia) {
                $bucket[] = $this->fromInterfaceAlert($ia);
            }

            $groups = [];
            foreach ($byCircuit as $cid => $g) {
                $c = $g['circuit'];
                $open = $openByCircuit->get($cid);
                $groups[] = [
                    'kind' => 'circuit',
                    'circuit' => [
                        'id' => $c->id, 'isp_name' => $c->isp_name, 'wan_interface' => $c->wan_interface,
                        'gateway_ip' => $c->gateway_ip, 'support_phone' => $c->support_phone,
                        'circuit_id' => $c->circuit_id, 'status' => $c->status,
                    ],
                    'state' => ($g['down'] ?? false) || strtolower((string) $c->status) === 'down' ? 'down' : 'degraded',
                    'ticket' => [
                        'isp_ticket' => optional($open)->ticket_number,
                        'dispatch_at' => optional($open)->dispatch_at,
                        'dispatch_note' => optional($open)->dispatch_note,
                        'dispatch_by_name' => optional(optional($open)->dispatchBy)->name,
                    ],
                    'alarms' => $g['alarms'],
                ];
            }
            usort($groups, fn ($x, $y) => [$this->stateRank($x['state']), (string) $x['circuit']['isp_name']]
                <=> [$this->stateRank($y['state']), (string) $y['circuit']['isp_name']]);

            if ($bucket !== []) {
                $groups[] = [
                    'kind' => 'site',
                    'circuit' => null,
                    'label' => $this->bucketLabel($bucket),
                    'state' => collect($bucket)->contains(fn ($a) => $a['severity'] === 'critical') ? 'critical' : 'warning',
                    'ticket' => null,
                    'alarms' => $bucket,
                ];
            }

            $out[] = ['site_id' => (int) $sid, 'site_name' => $siteNames[$sid] ?? null, 'groups' => $groups];
        }

        usort($out, fn ($x, $y) => $this->siteRank($x) <=> $this->siteRank($y));

        return $out;
    }

    private function isLinkDown(string $desc): bool
    {
        $h = strtolower($desc);

        return str_contains($h, 'link') && str_contains($h, 'down') && (bool) preg_match('/\bwan\d/', $h);
    }

    /** Name the site bucket by what's in it: power/appliance if the edge is down, else overlay/other. */
    private function bucketLabel(array $rows): string
    {
        foreach ($rows as $r) {
            if (str_contains(strtolower((string) $r['description']), 'unreachable') && str_contains(strtolower((string) $r['description']), 'device')) {
                return 'Power / appliance';
            }
        }
        $hasTunnel = collect($rows)->contains(fn ($r) => str_contains(strtolower((string) $r['description']), 'tunnel'));

        return $hasTunnel ? 'Overlay' : 'Other';
    }

    private function fromDeviceAlarm(DeviceAlarm $a): array
    {
        return [
            'key' => "da-{$a->id}", 'id' => $a->id, 'alarm_id' => $a->alarm_id,
            'severity' => $a->severity, 'description' => $a->description,
            'ticket_number' => $a->ticket_number, 'first_seen_at' => $a->first_seen_at,
            'device_name' => optional($a->device)->name, 'device_id' => $a->device_id, 'acknowledged_at' => $a->acknowledged_at,
            // In-place NOC actions (analyst+). A device alarm acks/clears itself;
            // it has no independent mute.
            'actions' => [
                'ack' => ['url' => "/api/alarms/{$a->id}/acknowledge"],
                'clear' => ['url' => "/api/alarms/{$a->id}/clear"],
                'mute' => null,
            ],
        ];
    }

    private function fromCircuitAlert(CircuitAlert $ca): array
    {
        $c = $ca->circuit;
        $loss = $ca->detected_loss_pct ? " · {$ca->detected_loss_pct}% loss" : '';

        return [
            'key' => "ca-{$ca->id}", 'id' => null, 'alarm_id' => null,
            'severity' => 'critical',
            'description' => "Circuit down — {$c->isp_name} {$c->circuit_id}{$loss}",
            'ticket_number' => $ca->ticket_number, 'first_seen_at' => $ca->started_at,
            'device_name' => null, 'device_id' => null, 'acknowledged_at' => $ca->acknowledged_at,
            // A circuit outage acks/clears via its circuit; "mute" = pause monitoring
            // (silences ALL of the circuit's alarms) — admin only.
            'actions' => [
                'ack' => ['url' => "/api/circuits/{$ca->circuit_id}/acknowledge"],
                'clear' => ['url' => "/api/circuits/{$ca->circuit_id}/clear"],
                'mute' => ['url' => "/api/circuits/{$ca->circuit_id}/monitoring", 'body' => ['enabled' => false], 'label' => 'Pause circuit', 'admin' => true],
            ],
        ];
    }

    private function fromInterfaceAlert(InterfaceAlert $ia): array
    {
        $if = $ia->deviceInterface;
        $nb = $if ? ($this->neighborMap[$if->device_id.':'.strtolower((string) $if->if_name)] ?? null) : null;
        $lastNeighbor = $nb
            ? trim(($nb->remote_sysname ?: $nb->remote_chassis_id ?: 'unknown').($nb->remote_port ? " · {$nb->remote_port}" : ''))
            : null;

        return [
            'key' => "ia-{$ia->id}", 'id' => null, 'alarm_id' => null,
            'severity' => $ia->severity ?: 'warning',
            'description' => 'Interface down — '.(optional($if)->if_name ?? 'port'),
            'last_neighbor' => $lastNeighbor,
            'macs' => $if ? ($this->macMap[$if->id]['list'] ?? []) : [],
            'macs_more' => $if ? ($this->macMap[$if->id]['more'] ?? 0) : 0,
            'ticket_number' => null, 'first_seen_at' => $ia->started_at,
            'device_name' => optional(optional($if)->device)->name, 'device_id' => optional(optional($if)->device)->id,
            'if_name' => optional($if)->if_name, 'acknowledged_at' => $ia->acknowledged_at,
            // Interface alerts ack/clear via the alert; "mute" = suppress the port.
            'actions' => [
                'ack' => ['url' => "/api/interface-alerts/{$ia->id}/acknowledge"],
                'clear' => ['url' => "/api/interface-alerts/{$ia->id}/clear"],
                'mute' => optional($if)->id ? ['url' => "/api/interfaces/{$if->id}/suppress", 'label' => 'Mute port', 'admin' => true] : null,
            ],
        ];
    }

    private function stateRank(string $s): int
    {
        return ['down' => 0, 'critical' => 0, 'degraded' => 1, 'warning' => 2][$s] ?? 3;
    }

    private function siteRank(array $site): int
    {
        return collect($site['groups'])->min(fn ($g) => $this->stateRank($g['state'])) ?? 3;
    }
}
