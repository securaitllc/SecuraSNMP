<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\NextHopPoller;
use App\Support\SshError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls ONE EdgeConnect device's WAN next-hops over SSH. Spawned by the concurrent
 * pool in `nexthops:poll` so the fleet sweep runs in parallel instead of sequentially.
 */
class PollNextHopsDevice extends Command
{
    protected $signature = 'nexthops:poll-device {id}';

    protected $description = "Poll one EdgeConnect device's WAN next-hops over SSH (concurrent-pool worker).";

    public function handle(): int
    {
        $device = Device::find((int) $this->argument('id'));
        if (! $device) {
            return self::SUCCESS;
        }

        try {
            NextHopPoller::forProduction()->poll($device);
        } catch (Throwable $e) {
            // A single unreachable/wedged appliance must not abort the pool — log and
            // exit cleanly so the parent just moves to the next device.
            Log::warning("Next-hop poll failed for device {$device->id}: ".SshError::safe($e->getMessage()));
        }

        return self::SUCCESS;
    }
}
