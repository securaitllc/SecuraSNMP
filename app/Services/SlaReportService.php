<?php

namespace App\Services;

use App\Models\CircuitAlert;
use App\Models\InterfaceAlert;
use App\Models\TunnelAlert;
use Carbon\Carbon;

/**
 * Availability / SLA reporting computed from the outage history. For each
 * monitored entity it derives uptime %, total downtime, incident count and MTTR
 * over a window, from the started_at/ended_at spans of its alerts.
 */
class SlaReportService
{
    /**
     * @return array<int, array{type: string, name: string, device: ?string, uptime_pct: float, downtime_seconds: int, incidents: int, mttr_seconds: ?int}>
     */
    public function report(int $hours): array
    {
        $end = now();
        $start = $end->copy()->subHours($hours);
        $rows = [];

        CircuitAlert::with('circuit.site')
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->get()
            ->groupBy('circuit_id')
            ->each(function ($alerts) use (&$rows, $start, $end): void {
                $circuit = $alerts->first()->circuit;
                if ($circuit) {
                    $rows[] = ['type' => 'circuit', 'name' => $circuit->circuit_id, 'device' => $circuit->site?->name]
                        + self::availability($alerts->map->only(['started_at', 'ended_at'])->all(), $start, $end);
                }
            });

        InterfaceAlert::with('deviceInterface.device')
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->get()
            ->groupBy('device_interface_id')
            ->each(function ($alerts) use (&$rows, $start, $end): void {
                $if = $alerts->first()->deviceInterface;
                if ($if) {
                    $rows[] = ['type' => 'interface', 'name' => $if->if_name, 'device' => $if->device?->name]
                        + self::availability($alerts->map->only(['started_at', 'ended_at'])->all(), $start, $end);
                }
            });

        TunnelAlert::with('tunnel.device')
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->get()
            ->groupBy('tunnel_id')
            ->each(function ($alerts) use (&$rows, $start, $end): void {
                $tunnel = $alerts->first()->tunnel;
                if ($tunnel) {
                    $rows[] = ['type' => 'tunnel', 'name' => $tunnel->tunnel_name, 'device' => $tunnel->device?->name]
                        + self::availability($alerts->map->only(['started_at', 'ended_at'])->all(), $start, $end);
                }
            });

        usort($rows, fn ($a, $b) => $a['uptime_pct'] <=> $b['uptime_pct']);

        return $rows;
    }

    /**
     * Uptime %, downtime, incidents and MTTR for a set of outage spans over a window.
     *
     * @param  array<int, array{started_at: Carbon, ended_at: ?Carbon}>  $spans
     * @return array{uptime_pct: float, downtime_seconds: int, incidents: int, mttr_seconds: ?int}
     */
    public static function availability(array $spans, Carbon $start, Carbon $end): array
    {
        // Integer timestamp math avoids Carbon 3's signed/float diffInSeconds.
        $windowSeconds = max(1, $end->getTimestamp() - $start->getTimestamp());
        $downtime = 0;
        $incidents = 0;
        $resolvedDurations = [];

        foreach ($spans as $span) {
            $s = $span['started_at']->greaterThan($start) ? $span['started_at'] : $start;
            $e = ($span['ended_at'] ?? $end);
            $e = $e->lessThan($end) ? $e : $end;

            if ($e->greaterThan($s)) {
                $downtime += $e->getTimestamp() - $s->getTimestamp();
                $incidents++;
            }

            if ($span['ended_at']) {
                $resolvedDurations[] = $span['ended_at']->getTimestamp() - $span['started_at']->getTimestamp();
            }
        }

        $uptime = max(0, min(100, 100 - ($downtime / $windowSeconds * 100)));

        return [
            'uptime_pct' => round($uptime, 3),
            'downtime_seconds' => $downtime,
            'incidents' => $incidents,
            'mttr_seconds' => $resolvedDurations ? (int) round(array_sum($resolvedDurations) / count($resolvedDurations)) : null,
        ];
    }
}
