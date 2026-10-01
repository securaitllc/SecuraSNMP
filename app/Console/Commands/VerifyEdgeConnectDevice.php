<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\SshVerifier;
use App\Support\SshError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies ONE EdgeConnect device's tunnels/next-hops over SSH. Spawned by the
 * concurrent pool in `edgeconnect:verify` so the fleet sweep runs in parallel.
 */
class VerifyEdgeConnectDevice extends Command
{
    protected $signature = 'edgeconnect:verify-device {id}';

    protected $description = 'Verify one EdgeConnect device over SSH (concurrent-pool worker).';

    public function handle(): int
    {
        $device = Device::find((int) $this->argument('id'));
        if (! $device) {
            return self::SUCCESS;
        }

        try {
            SshVerifier::forProduction()->verify($device);
        } catch (Throwable $e) {
            Log::warning("EdgeConnect verify failed for device {$device->id}: ".SshError::safe($e->getMessage()));
        }

        return self::SUCCESS;
    }
}
