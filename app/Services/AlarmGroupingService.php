<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\InterfaceAlert;
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

    public function grouped(?int $siteId = null): array
    {
        $deviceAlarms = DeviceAlarm::query()->whereNull('cleared_at')
            ->with('device:id,name,site_id,role')
            ->when($siteId, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('site_id', $siteId)))
            ->orderByDesc('first_seen_at')->get()
            ->filter(fn ($a) => $a->device && $a->device->site_id);

        $circuitAlerts = CircuitAlert::query()->whereNull('ended_at')
            ->with(['circuit', 'dispatchBy:id,name'])
            ->when($siteId, fn ($q) => $q->whereHas('circuit', fn ($c) => $c->where('site_id', $siteId)))
            ->get()->filter(fn ($a) => $a->circuit && $a->circuit->site_id);

        $ifaceAlerts = InterfaceAlert::query()->whereNull('ended_at')
            ->with(['deviceInterface:id,if_name,device_id', 'deviceInterface.device:id,name,site_id'])
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
            'device_name' => optional($a->device)->name, 'acknowledged_at' => $a->acknowledged_at,
            // NOC actions route by (type, id): a device alarm acks/clears itself.
            'action_type' => 'alarm', 'action_id' => $a->id,
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
            'device_name' => null, 'acknowledged_at' => $ca->acknowledged_at,
            // A circuit outage acks/clears via its circuit (operates on the open alert).
            'action_type' => 'circuit', 'action_id' => $ca->circuit_id,
        ];
    }

    private function fromInterfaceAlert(InterfaceAlert $ia): array
    {
        $if = $ia->deviceInterface;

        return [
            'key' => "ia-{$ia->id}", 'id' => null, 'alarm_id' => null,
            'severity' => $ia->severity ?: 'warning',
            'description' => 'Interface down — '.(optional($if)->if_name ?? 'port'),
            'ticket_number' => null, 'first_seen_at' => $ia->started_at,
            'device_name' => optional(optional($if)->device)->name, 'acknowledged_at' => null,
            'action_type' => 'interface', 'action_id' => $ia->id,
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
