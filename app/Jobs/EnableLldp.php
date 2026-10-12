<?php

namespace App\Jobs;

use App\Models\Device;
use App\Support\SshError;
use App\Support\SshSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes `lldp enable` on the Silver Peak's LAN interfaces over SSH, off the
 * request path — the single-threaded web server would otherwise block every
 * other request for the length of the SSH session. The device's
 * lldp_enable_status records the outcome for the UI.
 */
class EnableLldp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    /**
     * @param  array<int, string>  $interfaces
     */
    public function __construct(public int $deviceId, public array $interfaces)
    {
    }

    public function handle(): void
    {
        $device = Device::find($this->deviceId);
        if (! $device) {
            return;
        }

        try {
            $commands = ['conf t'];
            foreach ($this->interfaces as $intf) {
                $commands[] = "int {$intf} lldp enable";
            }
            $commands[] = 'exit';

            SshSession::run($device, $commands);

            $device->update([
                'lldp_enable_status' => 'LLDP enabled on '.implode(', ', $this->interfaces).' — the neighbor may take a few minutes to appear.',
                'lldp_enable_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error("LLDP enable job failed for device {$device->id}: ".SshError::safe($e->getMessage()));
            $device->update([
                'lldp_enable_status' => 'Failed: '.SshError::safe($e->getMessage()),
                'lldp_enable_at' => now(),
            ]);
        }
    }
}
