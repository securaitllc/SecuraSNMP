<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\Site;

/**
 * Active EdgeConnect alarms grouped by site → ISP circuit, so the NOC records one
 * ISP ticket + dispatch per provider instead of per-alarm. A ping-up circuit that
 * still has correlated alarms (tunnels/next-hop down over an ISP whose gateway
 * happens to answer via L2VPN) is "degraded"; a link/ping-down one is "down".
 * Alarms with no circuit (tunnel rollup, NTP) fall into a per-site bucket.
 */
class AlarmGroupingService
{
    public function __construct(private AlarmCircuitResolver $resolver = new AlarmCircuitResolver) {}

    /**
     * @param  int|null  $siteId  restrict to one site (Site detail); null = fleet (Alarms page)
     * @return list<array{site_id:int,site_name:?string,groups:array}>
     */
    public function grouped(?int $siteId = null): array
    {
        $alarms = DeviceAlarm::query()
            ->whereNull('cleared_at')
            ->with('device:id,name,site_id,role')
            ->when($siteId, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('site_id', $siteId)))
            ->orderByDesc('first_seen_at')
            ->get()
            ->filter(fn ($a) => $a->device && $a->device->site_id);

        if ($alarms->isEmpty()) {
            return [];
        }

        $siteIds = $alarms->pluck('device.site_id')->unique()->values()->all();
        $deviceIds = $alarms->pluck('device_id')->unique()->values()->all();

        $circuitsBySite = Circuit::whereIn('site_id', $siteIds)->get()->groupBy('site_id');
        $nextHopsByDevice = DeviceNextHop::whereIn('device_id', $deviceIds)->get()->groupBy('device_id');
        $siteNames = Site::whereIn('id', $siteIds)->pluck('name', 'id');

        $circuitIds = $circuitsBySite->flatten()->pluck('id')->all();
        $openAlerts = CircuitAlert::whereIn('circuit_id', $circuitIds)->whereNull('ended_at')
            ->with('dispatchBy:id,name')->get()->keyBy('circuit_id');

        $out = [];
        foreach ($alarms->groupBy('device.site_id') as $sid => $siteAlarms) {
            $circuits = $circuitsBySite->get($sid) ?? collect();
            $byCircuit = [];
            $siteBucket = [];

            foreach ($siteAlarms as $a) {
                $nh = $nextHopsByDevice->get($a->device_id) ?? collect();
                $c = $this->resolver->resolve($a->alarm_id, (string) $a->description, $circuits, $nh);
                if ($c) {
                    $byCircuit[$c->id]['circuit'] = $c;
                    $byCircuit[$c->id]['alarms'][] = $a;
                } else {
                    $siteBucket[] = $a;
                }
            }

            $groups = [];
            foreach ($byCircuit as $cid => $g) {
                $c = $g['circuit'];
                $open = $openAlerts->get($cid);
                $groups[] = [
                    'kind' => 'circuit',
                    'circuit' => [
                        'id' => $c->id,
                        'isp_name' => $c->isp_name,
                        'wan_interface' => $c->wan_interface,
                        'gateway_ip' => $c->gateway_ip,
                        'support_phone' => $c->support_phone,
                        'circuit_id' => $c->circuit_id,
                        'status' => $c->status,
                    ],
                    'state' => $this->circuitState($c, $g['alarms']),
                    'ticket' => [
                        'isp_ticket' => optional($open)->ticket_number,
                        'dispatch_at' => optional($open)->dispatch_at,
                        'dispatch_note' => optional($open)->dispatch_note,
                        'dispatch_by_name' => optional(optional($open)->dispatchBy)->name,
                    ],
                    'alarms' => array_map($this->serializeAlarm(...), $g['alarms']),
                ];
            }
            usort($groups, fn ($x, $y) => [$this->stateRank($x['state']), (string) $x['circuit']['isp_name']]
                <=> [$this->stateRank($y['state']), (string) $y['circuit']['isp_name']]);

            if ($siteBucket !== []) {
                $groups[] = [
                    'kind' => 'site',
                    'circuit' => null,
                    'state' => collect($siteBucket)->contains(fn ($a) => $a->severity === 'critical') ? 'critical' : 'warning',
                    'ticket' => null,
                    'alarms' => array_map($this->serializeAlarm(...), $siteBucket),
                ];
            }

            $out[] = ['site_id' => (int) $sid, 'site_name' => $siteNames[$sid] ?? null, 'groups' => $groups];
        }

        // Sites carrying a down/critical group float to the top.
        usort($out, fn ($x, $y) => $this->siteRank($x) <=> $this->siteRank($y));

        return $out;
    }

    /** A ping/link-down circuit is "down"; impaired-but-answering is "degraded". */
    private function circuitState(Circuit $c, array $alarms): string
    {
        if (strtolower((string) $c->status) === 'down') {
            return 'down';
        }
        foreach ($alarms as $a) {
            $h = strtolower((string) $a->description);
            if (str_contains($h, 'link') && str_contains($h, 'down') && preg_match('/\bwan\d/', $h)) {
                return 'down';
            }
        }

        return 'degraded';
    }

    private function stateRank(string $s): int
    {
        return ['down' => 0, 'critical' => 0, 'degraded' => 1, 'warning' => 2][$s] ?? 3;
    }

    private function siteRank(array $site): int
    {
        return collect($site['groups'])->min(fn ($g) => $this->stateRank($g['state'])) ?? 3;
    }

    private function serializeAlarm(DeviceAlarm $a): array
    {
        return [
            'id' => $a->id,
            'alarm_id' => $a->alarm_id,
            'severity' => $a->severity,
            'description' => $a->description,
            'ticket_number' => $a->ticket_number,
            'first_seen_at' => $a->first_seen_at,
            'device_name' => optional($a->device)->name,
            'acknowledged_at' => $a->acknowledged_at,
        ];
    }
}
