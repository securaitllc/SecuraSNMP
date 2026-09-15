<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PollsConcurrently;
use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use Illuminate\Console\Command;

class VerifyEdgeConnect extends Command
{
    use PollsConcurrently;
    use RunsPollLoop;

    protected $signature = 'edgeconnect:verify';

    protected $description = 'Continuously verifies alarms, tunnels, and next-hop reachability for each EdgeConnect device over SSH.';

    public function handle(): int
    {
        $interval = max(30, (int) config('monitoring.edgeconnect_interval'));
        $limit = (int) config('monitoring.ssh_concurrency', 6);

        $this->info("EdgeConnect verifier started, checking every {$interval}s (concurrency {$limit}).");

        // Concurrent pool (edgeconnect:verify-device) — same reason as nexthops: the
        // sequential SSH sweep lagged tunnel-alarm clearing by a full sweep.
        $this->pollForever('tunnels-ssh', $interval, function () use ($limit): void {
            $ids = Device::where('role', 'edgeconnect')->pluck('id')->all();
            $this->pollConcurrentlyVia($ids, 'edgeconnect:verify-device', $limit);
        });

        return self::SUCCESS;
    }
}
