<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Reports the liveness of every long-running poller loop from the heartbeat files
 * that RunsPollLoop writes each cycle.
 *
 * circuits:monitor has died on Massey production twice (2026-07-26, 2026-07-27) and
 * gone unnoticed for ~20h each time because its sibling loops kept running. Their
 * response-time history simply stopped and every circuit kept displaying its last
 * known state — including a recovered circuit that could never auto-clear because
 * nothing re-pinged it. This endpoint makes such a stall visible in seconds.
 */
class PollerHealthController extends Controller
{
    /**
     * The heartbeat-emitting loops (label => human name). Must match the labels
     * passed to pollForever(). syslog:listen and queue:work are NOT here — they
     * do not use the poll loop, so they get restart-on-death supervision only.
     *
     * @var array<string, string>
     */
    private const POLLERS = [
        'circuits' => 'Circuit monitor (ICMP/SD-WAN ping)',
        'devices' => 'Device reachability',
        'interfaces' => 'Interface stats (SNMP)',
        'health' => 'Device health + identity (SNMP)',
        'ec-alarms' => 'EdgeConnect alarms (SNMP)',
        'nexthops' => 'Next-hop table (SSH)',
        'tunnels-ssh' => 'Tunnel verify (SSH)',
        'lldp' => 'LLDP discovery',
        'arp' => 'ARP table (IP↔MAC, SNMP)',
        'macs' => 'MAC table learning (SNMP)',
        'prune' => 'Metric-history pruning',
        'vuln' => 'Vulnerability correlation (passive)',
        'anomaly' => 'Baseline anomaly detection (passive)',
    ];

    public function index(): JsonResponse
    {
        $dir = storage_path('app/pollers');
        $now = time();
        $pollers = [];

        foreach (self::POLLERS as $label => $name) {
            $file = "{$dir}/{$label}.beat";

            if (! is_file($file)) {
                $pollers[] = [
                    'poller' => $label,
                    'name' => $name,
                    'last_beat_at' => null,
                    'age_seconds' => null,
                    'interval_seconds' => null,
                    'threshold_seconds' => null,
                    'stale' => true,
                    'status' => 'missing',
                ];

                continue;
            }

            [$ts, $interval] = array_pad(
                preg_split('/\s+/', trim((string) @file_get_contents($file))) ?: [],
                2,
                null
            );
            $ts = (int) $ts;
            $interval = max((int) $interval, 1);
            $age = $now - $ts;
            // Generous: three missed cycles, but never trip inside 3 minutes so a
            // brief GC pause or slow sweep is not mistaken for a dead loop.
            $threshold = max($interval * 3, 180);
            $stale = $age > $threshold;

            $pollers[] = [
                'poller' => $label,
                'name' => $name,
                'last_beat_at' => gmdate('c', $ts),
                'age_seconds' => $age,
                'interval_seconds' => $interval,
                'threshold_seconds' => $threshold,
                'stale' => $stale,
                'status' => $stale ? 'stale' : 'ok',
            ];
        }

        $healthy = collect($pollers)->every(fn (array $p) => ! $p['stale']);

        return response()->json([
            'healthy' => $healthy,
            'checked_at' => gmdate('c', $now),
            'pollers' => $pollers,
        ], $healthy ? 200 : 503);
    }
}
