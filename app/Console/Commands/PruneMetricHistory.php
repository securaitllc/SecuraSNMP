<?php

namespace App\Console\Commands;

use App\Models\CircuitMetricHistory;
use App\Models\DeviceHealthHistory;
use App\Models\DeviceMetricHistory;
use App\Models\InterfaceMetricHistory;
use App\Models\NotificationLog;
use App\Models\SyslogMessage;
use App\Models\TunnelMetricHistory;
use Illuminate\Console\Command;

class PruneMetricHistory extends Command
{
    protected $signature = 'metrics:prune {--loop : Run forever, pruning once a day}';

    protected $description = 'Deletes metric/health history, syslog and notification logs older than 30 days.';

    public function handle(): int
    {
        if (! $this->option('loop')) {
            $this->pruneOnce();

            return self::SUCCESS;
        }

        $this->info('Metric history pruner started, running once a day.');

        while (true) {
            $this->pruneOnce();
            sleep(86400);
        }
    }

    private function pruneOnce(): void
    {
        $cutoff = now()->subDays(30);

        InterfaceMetricHistory::where('recorded_at', '<', $cutoff)->delete();
        TunnelMetricHistory::where('recorded_at', '<', $cutoff)->delete();
        CircuitMetricHistory::where('recorded_at', '<', $cutoff)->delete();
        DeviceMetricHistory::where('recorded_at', '<', $cutoff)->delete();
        DeviceHealthHistory::where('recorded_at', '<', $cutoff)->delete();
        // High-volume operational tables — bound them so they can't fill the disk.
        SyslogMessage::where('received_at', '<', $cutoff)->delete();
        NotificationLog::where('created_at', '<', $cutoff)->delete();
    }
}
