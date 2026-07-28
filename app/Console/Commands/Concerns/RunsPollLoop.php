<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The shared body of every long-running poller loop.
 *
 * Each poller is an artisan command started once by the container entrypoint and
 * expected to run forever. A bare `while (true) { $tick(); sleep($n); }` means a
 * single unhandled Throwable — a DB deadlock, a DNS blip, an SSH library error —
 * terminates the process. Nothing restarts an individual loop, so that poller stays
 * dead until someone restarts the whole container, while its siblings keep running
 * and hide the fact that anything is wrong.
 *
 * Not hypothetical: circuits:monitor died on Massey production at
 * 2026-07-26 11:10:58Z and went unnoticed for ~25 hours because the tunnel and
 * device loops carried on. Circuit response-time history simply stopped, and every
 * circuit kept displaying its last known "up".
 *
 * So: one failing iteration must never end the loop. Log it, back off, continue.
 * This is the loop-level form of the fleet-isolation rule that already governs
 * per-device work — one bad iteration must not starve the fleet.
 */
trait RunsPollLoop
{
    /** Longest gap between attempts while failing, so recovery is still noticed. */
    private const MAX_BACKOFF_SECONDS = 600;

    /**
     * Run $tick every $seconds, forever, surviving any exception it throws.
     *
     * @param  callable():void  $tick  one polling pass
     */
    protected function pollForever(string $label, int $seconds, callable $tick): void
    {
        // Boot beacon: proves the process started even before its first (possibly
        // long-interval) tick completes, so the supervisor's start-up grace holds.
        $this->recordHeartbeat($label, $seconds);

        $consecutiveFailures = 0;

        while (true) {
            $consecutiveFailures = $this->runPollIteration($label, $tick, $consecutiveFailures);
            // Written only AFTER the iteration returns. A tick that HANGS never
            // reaches here, so the beat goes stale and the supervisor kills and
            // restarts this poller — the one failure mode try/catch cannot cover.
            $this->recordHeartbeat($label, $seconds);
            $this->sleepFor($this->backoffSeconds($seconds, $consecutiveFailures));
        }
    }

    /**
     * Liveness beacon. Each poller runs under an independent supervisor
     * (docker/entrypoint.sh) that restarts it on death and kills+restarts it when
     * this beat goes stale past max(3 * interval, 180s) — catching hangs. The file
     * holds "<unixtime> <interval_seconds>" so the supervisor and the
     * /api/health/pollers endpoint know each poller's expected cadence.
     *
     * A heartbeat write must NEVER take a poller down: all failures are swallowed.
     *
     * Seam: overridden in tests so the loop can be driven without touching disk.
     */
    protected function recordHeartbeat(string $label, int $intervalSeconds): void
    {
        try {
            $dir = storage_path('app/pollers');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents("{$dir}/{$label}.beat", time().' '.$intervalSeconds."\n");
        } catch (Throwable) {
            // Disk full / permissions — never fatal to the poller itself.
        }
    }

    /**
     * One guarded iteration. Returns the new consecutive-failure count: 0 on
     * success, incremented on failure. Never throws.
     */
    protected function runPollIteration(string $label, callable $tick, int $consecutiveFailures): int
    {
        try {
            $tick();

            return 0;
        } catch (Throwable $e) {
            $consecutiveFailures++;

            Log::error("{$label} poll iteration failed", [
                'poller' => $label,
                'consecutive_failures' => $consecutiveFailures,
                'exception' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $this->error("[{$label}] iteration failed (#{$consecutiveFailures}): {$e->getMessage()}");

            return $consecutiveFailures;
        }
    }

    /**
     * Normal cadence while healthy; linear back-off while failing so a database
     * that is down is not hammered every interval, capped so recovery is still
     * detected promptly.
     */
    protected function backoffSeconds(int $seconds, int $consecutiveFailures): int
    {
        if ($consecutiveFailures === 0) {
            return $seconds;
        }

        return min($seconds * $consecutiveFailures, self::MAX_BACKOFF_SECONDS);
    }

    /** Seam: overridden in tests so the loop can be driven without sleeping. */
    protected function sleepFor(int $seconds): void
    {
        sleep($seconds);
    }
}
