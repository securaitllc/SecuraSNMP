<?php

namespace App\Console\Commands;

use App\Services\NextHopPoller;
use Illuminate\Console\Command;

class PollNextHops extends Command
{
    protected $signature = 'nexthops:poll';

    protected $description = "Polls each Silver Peak's WAN next-hops over SSH and alarms on unreachable ones.";

    public function handle(): int
    {
        $poller = NextHopPoller::forProduction();
        $interval = max(30, (int) config('monitoring.nexthop_interval'));
        $this->info("Next-hop poller started, running every {$interval}s.");

        while (true) {
            $poller->pollAll();
            sleep($interval);
        }
    }
}
