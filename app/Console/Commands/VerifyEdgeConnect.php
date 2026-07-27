<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Services\SshVerifier;
use Illuminate\Console\Command;

class VerifyEdgeConnect extends Command
{
    use RunsPollLoop;

    protected $signature = 'edgeconnect:verify';

    protected $description = 'Continuously verifies alarms, tunnels, and next-hop reachability for each EdgeConnect device over SSH.';

    public function handle(): int
    {
        $verifier = SshVerifier::forProduction();
        $interval = max(30, (int) config('monitoring.edgeconnect_interval'));

        $this->info("EdgeConnect verifier started, checking every {$interval}s.");

        $this->pollForever('tunnels-ssh', $interval, function () use ($verifier): void {
            $verifier->verifyAll();
        });
    }
}
