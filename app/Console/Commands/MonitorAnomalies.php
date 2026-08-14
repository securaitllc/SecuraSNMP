<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Anomaly;
use App\Services\AnomalyDetector;
use Illuminate\Console\Command;

class MonitorAnomalies extends Command
{
    use RunsPollLoop;

    protected $signature = 'anomaly:monitor';

    protected $description = 'Continuously flags metrics that deviate from an entity\'s own baseline (non-paging anomalies).';

    public function handle(): int
    {
        $interval = max(120, (int) env('POLL_ANOMALY_SECONDS', 600));
        $this->info("Anomaly detector started, scanning every {$interval}s.");

        $detector = new AnomalyDetector;

        $this->pollForever('anomaly', $interval, function () use ($detector): void {
            // No device I/O — this reads stored metric history only, so it is cheap and
            // passive. beat() per entity keeps the supervisor happy on a long sweep.
            $detector->scanInterfaces(fn () => $this->beat());
            $detector->scanCircuits(fn () => $this->beat());

            // Close out anomalies a healthy sweep can't be maintaining (impossible z,
            // or an entity that has dropped out of the scan set) — untouched for 3
            // sweeps = orphaned.
            $detector->resolveStale($interval * 3);

            // Drop resolved anomalies older than 14 days so the table stays bounded.
            Anomaly::whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(14))->delete();
        });

        return self::SUCCESS;
    }
}
