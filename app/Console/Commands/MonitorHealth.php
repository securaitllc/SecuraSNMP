<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorHealth extends Command
{
    use RunsPollLoop;

    protected $signature = 'health:monitor';

    protected $description = "Continuously polls each SNMP-credentialed device's CPU, memory, temperature and environmental sensors.";

    public function handle(): int
    {
        $this->info('Health monitor started, polling every 5 minutes.');

        // A sequential SNMP sweep of ~270 appliances took 30-40 minutes, so devices
        // at the end (the EdgeConnects) were starved of identity/health updates. Poll
        // them CONCURRENTLY instead — a bounded pool of per-device subprocesses
        // (health:poll-device) collapses the sweep to a couple of minutes.
        $this->pollForever('health', 300, function (): void {
            $ids = Device::whereNotNull('snmp_community')
                ->orWhereNotNull('snmp_v3_username')
                ->pluck('id')
                ->all();

            $this->pollConcurrently($ids);
        });
    }

    /** @param list<int> $ids */
    private function pollConcurrently(array $ids): void
    {
        $limit = max(1, (int) config('monitoring.health_concurrency', 10));
        $queue = $ids;
        $running = [];   // id => Process

        $fill = function () use (&$queue, &$running, $limit) {
            while (count($running) < $limit && $queue) {
                $id = array_shift($queue);
                $process = new Process(['php', 'artisan', 'health:poll-device', (string) $id], base_path());
                // A single device's poll is a handful of bounded snmpwalks; cap the
                // subprocess generously so it can't wedge the pool.
                $process->setTimeout(120);
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
                    // Enforce the 120s cap (Symfony only checks the timeout when
                    // asked) so a device wedged on SNMP can't stall the pool.
                    $process->checkTimeout();
                } catch (Throwable $e) {
                    $process->stop(0);
                    unset($running[$id]);
                    $this->beat();

                    continue;
                }
                if (! $process->isRunning()) {
                    unset($running[$id]);
                    // Beat as each device finishes so a long-but-progressing sweep
                    // is never mistaken for a hung loop by the supervisor.
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
