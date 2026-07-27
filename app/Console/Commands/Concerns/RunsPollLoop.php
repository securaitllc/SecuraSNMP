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
        $consecutiveFailures = 0;

        while (true) {
            $consecutiveFailures = $this->runPollIteration($label, $tick, $consecutiveFailures);
            $this->sleepFor($this->backoffSeconds($seconds, $consecutiveFailures));
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
