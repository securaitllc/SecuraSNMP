<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Models\MacAddress;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorMacTable extends Command
{
    use RunsPollLoop;

    protected $signature = 'mac:monitor';

    protected $description = 'Continuously learns every device\'s MAC forwarding table (SNMP) and prunes stale history.';

    public function handle(): int
    {
        $interval = (int) env('POLL_MAC_SECONDS', 900);
        $this->info("MAC table monitor started, polling every {$interval}s.");

        $this->pollForever('macs', $interval, function (): void {
            $ids = Device::whereNotNull('snmp_community')
                ->orWhereNotNull('snmp_v3_username')
                ->pluck('id')->all();

            $this->pollConcurrently($ids);
            $this->prune();
        });
    }

    /** Bounded pool of per-device subprocesses (SNMP is slow; don't sweep serially). */
    private function pollConcurrently(array $ids): void
    {
        $limit = max(1, (int) config('monitoring.health_concurrency', 10));
        $queue = $ids;
        $running = [];

        $fill = function () use (&$queue, &$running, $limit) {
            while (count($running) < $limit && $queue) {
                $id = array_shift($queue);
                $process = new Process(['php', 'artisan', 'mac:poll-device', (string) $id], base_path());
                $process->setTimeout(60);
                try {
                    $process->start();
                    $running[$id] = $process;
                } catch (Throwable) {
                    // couldn't spawn — skip this device this cycle
                }
            }
        };

        $fill();
        while ($running) {
            usleep(100_000);
            foreach ($running as $id => $process) {
                if (! $process->isRunning()) {
                    unset($running[$id]);
                }
            }
            $fill();
        }
    }

    /** Drop MACs not seen within the retention window (keeps the table bounded). */
    private function prune(): void
    {
        $days = max(1, (int) env('MAC_HISTORY_RETENTION_DAYS', 90));
        MacAddress::where('last_seen_at', '<', now()->subDays($days))->delete();
    }
}
