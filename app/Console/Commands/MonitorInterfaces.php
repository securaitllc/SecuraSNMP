<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorInterfaces extends Command
{
    use RunsPollLoop;

    protected $signature = 'interfaces:monitor';

    protected $description = "Continuously polls each SNMP-credentialed device's interfaces and records status/discard changes.";

    public function handle(): int
    {
        $interval = max(30, (int) config('monitoring.interface_interval'));
        $this->info("Interface monitor started, polling every {$interval}s.");

        // A sequential ifTable sweep let a few slow/dead switches starve the rest —
        // newly-added switches at the end of the list never got their interfaces. Poll
        // devices CONCURRENTLY instead: a bounded pool of per-device subprocesses
        // (interfaces:poll-device), isolated so one wedged appliance can't stall others.
        $this->pollForever('interfaces', $interval, function (): void {
            $ids = Device::whereNotNull('snmp_version')
                ->where(function ($query) {
                    $query->where(function ($v2c) {
                        $v2c->where('snmp_version', 'v2c')->whereNotNull('snmp_community');
                    })->orWhere(function ($v3) {
                        $v3->where('snmp_version', 'v3')
                            ->whereNotNull('snmp_v3_username')
                            ->whereNotNull('snmp_v3_auth_key')
                            ->whereNotNull('snmp_v3_priv_key');
                    });
                })
                ->pluck('id')
                ->all();

            $this->pollConcurrently($ids);
        });
    }

    /** @param list<int> $ids */
    private function pollConcurrently(array $ids): void
    {
        $limit = max(1, (int) config('monitoring.interface_concurrency', 10));
        $queue = $ids;
        $running = [];   // id => Process

        $fill = function () use (&$queue, &$running, $limit) {
            while (count($running) < $limit && $queue) {
                $id = array_shift($queue);
                $process = new Process(['php', 'artisan', 'interfaces:poll-device', (string) $id], base_path());
                // A full ifTable walk is heavier than health; cap generously so a
                // device wedged on SNMP can't wedge the whole pool.
                $process->setTimeout(180);
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
                usleep(100_000);
            }
            $fill();
        }
    }
}
