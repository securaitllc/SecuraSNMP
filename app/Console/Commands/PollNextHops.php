<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PollsConcurrently;
use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use Illuminate\Console\Command;

class PollNextHops extends Command
{
    use PollsConcurrently;
    use RunsPollLoop;

    protected $signature = 'nexthops:poll';

    protected $description = "Polls each Silver Peak's WAN next-hops over SSH and alarms on unreachable ones.";

    public function handle(): int
    {
        $interval = max(30, (int) config('monitoring.nexthop_interval'));
        $limit = (int) config('monitoring.ssh_concurrency', 6);
        $this->info("Next-hop poller started, running every {$interval}s (concurrency {$limit}).");

        // A sequential SSH sweep of 131 EdgeConnect appliances took ~260s — right at the
        // stale threshold — so next-hop alerts cleared a full sweep late. Poll CONCURRENTLY
        // via a bounded pool (nexthops:poll-device), beating per device so the loop never
        // reads as hung while it progresses.
        $this->pollForever('nexthops', $interval, function () use ($limit): void {
            $ids = Device::where('role', 'edgeconnect')->pluck('id')->all();
            $this->pollConcurrentlyVia($ids, 'nexthops:poll-device', $limit);
        });

        return self::SUCCESS;
    }
}
