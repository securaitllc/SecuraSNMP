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
        'fg-alarms' => 'FortiGate alarms (SNMP)',
        'nexthops' => 'Next-hop table (SSH)',
        'tunnels-ssh' => 'Tunnel verify (SSH)',
        'lldp' => 'LLDP discovery',
        'arp' => 'ARP table (IP↔MAC, SNMP)',
        'addresses' => 'Interface addresses (ipAddrTable, SNMP)',
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
                    'failing' => false,
                    'last_success_at' => null,
                    'restarts_since_success' => 0,
                    'last_failure' => null,
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

            // A beating loop is not a working loop. The vulnerability poller beat
            // every few seconds for two months on Massey production while completing
            // zero cycles: it exhausted the container's 128 MB limit mid-cycle, the
            // supervisor restarted it, the restart wrote a fresh beat, and this
            // endpoint called that `ok`. Every device's findings stayed frozen on
            // whatever firmware it ran at the last pass that did finish, and the only
            // place the fatal appeared was a log file nobody was reading.
            //
            // So liveness and progress are reported separately. A caught failure
            // leaves .fail; a fatal leaves no exception at all, only a restart with no
            // success in between, which is what .boot counts.
            $failure = $this->readFailure($dir, $label);
            $restarts = $this->readRestarts($dir, $label);
            $lastSuccess = $this->readSuccess($dir, $label);
            $failing = $failure !== null || $restarts >= 2;

            $pollers[] = [
                'poller' => $label,
                'name' => $name,
                'last_beat_at' => gmdate('c', $ts),
                'age_seconds' => $age,
                'interval_seconds' => $interval,
                'threshold_seconds' => $threshold,
                'stale' => $stale,
                'failing' => $failing,
                'last_success_at' => $lastSuccess !== null ? gmdate('c', $lastSuccess) : null,
                'restarts_since_success' => $restarts,
                'last_failure' => $failure,
                'status' => match (true) {
                    $stale => 'stale',
                    $failing => 'failing',
                    default => 'ok',
                },
            ];
        }

        $healthy = collect($pollers)->every(fn (array $p) => ! $p['stale'] && ! ($p['failing'] ?? false));

        // A monitoring platform that cannot reach anyone is not monitoring, it is a
        // dashboard. Nodus polled this fleet for weeks with ZERO notification channels
        // configured: every alert was dispatched, queued, delivered to nobody, and
        // logged nothing — and nothing in the app ever said so. The blind spot has to
        // be reported by the thing that has it.
        // Same readiness rule the dashboard uses: an enabled channel with no
        // destination, or a critical-only one, is not "can notify".
        $alerting = DashboardController::alertingSnapshot();

        return response()->json([
            'healthy' => $healthy,
            'checked_at' => gmdate('c', $now),
            'pollers' => $pollers,
            'alerting' => $alerting,
        ], $healthy ? 200 : 503);
    }

    /**
     * The last caught iteration failure, as RunsPollLoop recorded it.
     *
     * @return array<string, mixed>|null
     */
    private function readFailure(string $dir, string $label): ?array
    {
        $file = "{$dir}/{$label}.fail";
        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);
        if (! is_array($decoded)) {
            // A marker we cannot read still means the last cycle failed.
            return ['message' => 'an iteration failed but its detail could not be read'];
        }

        if (isset($decoded['at'])) {
            $decoded['at'] = gmdate('c', (int) $decoded['at']);
        }

        return $decoded;
    }

    /** Process starts since the last completed cycle. Two or more is a restart loop. */
    private function readRestarts(string $dir, string $label): int
    {
        $file = "{$dir}/{$label}.boot";
        if (! is_file($file)) {
            return 0;
        }

        [, $count] = array_pad(
            preg_split('/\s+/', trim((string) @file_get_contents($file))) ?: [],
            2,
            0
        );

        return (int) $count;
    }

    private function readSuccess(string $dir, string $label): ?int
    {
        $file = "{$dir}/{$label}.ok";
        if (! is_file($file)) {
            return null;
        }

        $ts = (int) trim((string) @file_get_contents($file));

        return $ts > 0 ? $ts : null;
    }
}
