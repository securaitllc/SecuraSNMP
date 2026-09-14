<?php

namespace App\Console\Commands\Concerns;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs a per-device artisan command across a bounded subprocess pool, beating once
 * per completed device. Mirrors the proven pool in MonitorHealth so a slow SSH sweep
 * of ~131 EdgeConnect appliances (sequentially ~260s — right at the stale threshold)
 * collapses to a fraction of that AND never reads as a hung loop while it progresses.
 *
 * Requires the host command to also use RunsPollLoop (for beat()).
 */
trait PollsConcurrently
{
    /**
     * @param  list<int>  $ids
     * @param  string  $command  a `{name} {id}` artisan command (e.g. 'nexthops:poll-device')
     * @param  int  $limit  max concurrent subprocesses
     * @param  int  $timeout  per-device hard cap (seconds) so a wedged appliance can't hold a slot forever
     */
    protected function pollConcurrentlyVia(array $ids, string $command, int $limit, int $timeout = 120): void
    {
        $limit = max(1, $limit);
        $queue = $ids;
        $running = [];   // id => Process

        $fill = function () use (&$queue, &$running, $limit, $command, $timeout) {
            while (count($running) < $limit && $queue) {
                $id = array_shift($queue);
                $process = new Process(['php', 'artisan', $command, (string) $id], base_path());
                $process->setTimeout($timeout);
                try {
                    $process->start();
                    $running[$id] = $process;
                } catch (Throwable $e) {
                    // Couldn't spawn — skip this device this cycle rather than abort.
                }
            }
        };

        $fill();
        while ($running) {
            foreach ($running as $id => $process) {
                try {
                    // Symfony only enforces the timeout when asked — so a device wedged
                    // on SSH can't stall the whole pool past $timeout.
                    $process->checkTimeout();
                } catch (Throwable $e) {
                    $process->stop(0);
                    unset($running[$id]);
                    $this->beat();

                    continue;
                }
                if (! $process->isRunning()) {
                    unset($running[$id]);
                    // Beat as each device finishes so a long-but-progressing sweep is
                    // never mistaken for a hung loop by the supervisor.
                    $this->beat();
                }
            }
            if ($running) {
                usleep(100_000); // 100ms between polls of the running set
            }
            $fill();
        }
    }
}
