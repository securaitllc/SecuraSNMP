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
            // Cleanup runs FIRST and is cheap (one UPDATE) — the interface scan below reads
            // 7 days of history for every up interface and can take many minutes on a big
            // fleet, so anything after it may be delayed a whole slow cycle or cut short by
            // the supervisor. Running resolveStale up front guarantees stale/impossible-z
            // anomalies clear every tick regardless of scan duration. (ge-0/0/46's z=2.8e12
            // row sat open for days because cleanup only ran after the long scan.)
            try {
                $detector->resolveStale($interval * 3);
                Anomaly::whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(14))->delete();
            } catch (Throwable $e) {
                Log::error('anomaly resolveStale failed: '.$e->getMessage());
            }

            // Each scan is isolated so one failing must not skip the other.
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
            try {
                $detector->scanDevices(fn () => $this->beat());
            } catch (Throwable $e) {
                Log::error('anomaly scanDevices failed: '.$e->getMessage());
            }
        });

        return self::SUCCESS;
    }
}
