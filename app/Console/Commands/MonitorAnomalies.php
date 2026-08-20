<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Anomaly;
use App\Services\AnomalyDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $this->pollForever('anomaly', $interval, function () use ($detector, $interval): void {
            // Each phase is ISOLATED: a failure in a scan (a bad row, a transient DB error)
            // must NOT skip resolveStale — otherwise a stale/impossible-z anomaly (an entity
            // that went down, or a legacy pre-clamp row) lingers open forever. That was the
            // ge-0/0/46 case: a down port's z=2.8e12 anomaly never cleared because a scan
            // threw before cleanup ran.
            try {
                $detector->scanInterfaces(fn () => $this->beat());
            } catch (Throwable $e) {
                Log::error('anomaly scanInterfaces failed: '.$e->getMessage());
            }
            try {
                $detector->scanCircuits(fn () => $this->beat());
            } catch (Throwable $e) {
                Log::error('anomaly scanCircuits failed: '.$e->getMessage());
            }

            // Close out anomalies a healthy sweep can't be maintaining (impossible z, or an
            // entity that dropped out of the scan set — went down / un-monitored). Always runs.
            try {
                $detector->resolveStale($interval * 3);
                Anomaly::whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(14))->delete();
            } catch (Throwable $e) {
                Log::error('anomaly resolveStale failed: '.$e->getMessage());
            }
        });

        return self::SUCCESS;
    }
}
