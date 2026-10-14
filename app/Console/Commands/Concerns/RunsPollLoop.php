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

    /** Label + interval of the loop currently running, so beat() can re-emit. */
    private string $pollLabel = '';

    private int $pollInterval = 0;

    /**
     * Run $tick every $seconds, forever, surviving any exception it throws.
     *
     * @param  callable():void  $tick  one polling pass
     */
    protected function pollForever(string $label, int $seconds, callable $tick): void
    {
        $this->pollLabel = $label;
        $this->pollInterval = $seconds;

        // Boot beacon: proves the process started even before its first (possibly
        // long-interval) tick completes, so the supervisor's start-up grace holds.
        $this->recordHeartbeat($label, $seconds);
        $this->recordBoot($label);

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
     * Progress beat, emitted from WITHIN a long tick so the supervisor can tell a
     * slow-but-healthy sweep from a wedged one.
     *
     * A fleet sweep (e.g. 240+ circuits pinged sequentially) legitimately runs far
     * longer than 3 * interval. Beating only once per completed sweep made the hang
     * watchdog kill the sweep mid-flight and restart it from the top, so circuits
     * late in the ordering were never reached — the exact starvation the
     * fleet-isolation rule forbids. Calling beat() per item keeps the heartbeat
     * fresh through a healthy sweep; only a genuine multi-minute stall on a single
     * item (beyond its own bounded timeout) lets the beat go stale and trip a
     * restart. Safe to call from any tick; a no-op before pollForever() runs.
     */
    /**
     * Arm the heartbeat without entering the loop — for a command that performs ONE
     * pass and exits, leaving the supervisor's restart-on-exit to provide the cadence.
     */
    protected function armHeartbeat(string $label, int $seconds): void
    {
        $this->pollLabel = $label;
        $this->pollInterval = $seconds;
        $this->recordHeartbeat($label, $seconds);
    }

    protected function beat(): void
    {
        if ($this->pollLabel !== '') {
            $this->recordHeartbeat($this->pollLabel, $this->pollInterval);
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
            $this->recordSuccess($label);

            return 0;
        } catch (Throwable $e) {
            $consecutiveFailures++;

            Log::error("{$label} poll iteration failed", [
                'poller' => $label,
                'consecutive_failures' => $consecutiveFailures,
                'exception' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $this->recordFailure($label, $consecutiveFailures, $e);
            $this->error("[{$label}] iteration failed (#{$consecutiveFailures}): {$e->getMessage()}");

            return $consecutiveFailures;
        }
    }

    /**
     * Mark a completed cycle, and clear whatever the last failure left behind.
     *
     * The liveness heartbeat cannot answer "is this loop getting its work done".
     * A poller killed mid-cycle — by the OOM killer, by the supervisor's hang
     * watchdog — restarts, writes a fresh boot beacon, and reads as healthy. The
     * vulnerability loop did exactly that for two months on Massey production: it
     * never completed one cycle, and /api/health/pollers said `ok` the whole time
     * because something had beaten recently. Absence of a completed cycle is the
     * signal, and only a success marker can carry it.
     */
    protected function recordSuccess(string $label): void
    {
        $dir = $this->pollerStateDir();
        if ($dir === null) {
            return;
        }

        @file_put_contents("{$dir}/{$label}.ok", time()."\n");
        @unlink("{$dir}/{$label}.fail");
        // Restart counting is "restarts since the last completed cycle", so a success
        // resets it. A loop that keeps dying before finishing is what we want to see.
        @file_put_contents("{$dir}/{$label}.boot", time()." 0\n");
    }

    /** Record why the last cycle failed, for /api/health/pollers to report. */
    protected function recordFailure(string $label, int $consecutiveFailures, Throwable $e): void
    {
        $dir = $this->pollerStateDir();
        if ($dir === null) {
            return;
        }

        @file_put_contents("{$dir}/{$label}.fail", json_encode([
            'at' => time(),
            'consecutive_failures' => $consecutiveFailures,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'where' => $e->getFile().':'.$e->getLine(),
        ]));
    }

    /**
     * Count this process start.
     *
     * A fatal — memory exhaustion above all — leaves no exception to catch and no
     * failure marker, so the only trace a killed cycle leaves is that the process
     * started again without a success in between. This counter is that trace.
     */
    protected function recordBoot(string $label): void
    {
        $dir = $this->pollerStateDir();
        if ($dir === null) {
            return;
        }

        $count = 0;
        $file = "{$dir}/{$label}.boot";
        if (is_file($file)) {
            [, $count] = array_pad(preg_split('/\s+/', trim((string) @file_get_contents($file))) ?: [], 2, 0);
        }

        @file_put_contents($file, time().' '.((int) $count + 1)."\n");
    }

    /** The shared poller-state directory, or null if it cannot be used. */
    private function pollerStateDir(): ?string
    {
        try {
            $dir = storage_path('app/pollers');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            return is_dir($dir) ? $dir : null;
        } catch (Throwable) {
            // Disk full / permissions — never fatal to the poller itself.
            return null;
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
