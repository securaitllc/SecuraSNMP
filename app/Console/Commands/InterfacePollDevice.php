<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\InterfacePoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Polls ONE device's interfaces. It exists so interfaces:monitor can run many of
 * these concurrently (a bounded subprocess pool) instead of walking ~270 appliances
 * one at a time — a sequential SNMP sweep let a few slow/dead switches starve the
 * rest, so newly-added switches at the end of the list never got their interfaces.
 *
 * Each invocation is isolated: one appliance's misbehaving SNMP can't affect another.
 */
class InterfacePollDevice extends Command
{
    protected $signature = 'interfaces:poll-device {id}';

    protected $description = 'Poll a single device\'s interfaces (spawned concurrently by interfaces:monitor).';

    public function handle(): int
    {
        $device = Device::find($this->argument('id'));
        if (! $device) {
            return self::SUCCESS;
        }

        $walker = function (Device $device, string $oid): string {
            $process = new Process($this->snmpWalkCommand($device, $oid));
            $process->setTimeout(30); // hard kill — don't wait the 60s default on a wedged walk
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(
                    "snmpwalk failed for device {$device->ip_address} (OID: {$oid}): "
                    .($process->getErrorOutput() ?: 'Exit code: '.$process->getExitCode())
                );
            }

            return $process->getOutput();
        };

        try {
            (new InterfacePoller($walker))->poll($device);
        } catch (Throwable $e) {
            Log::error("Interface poll failed for device {$device->id}: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }

    /**
     * Bounded -t/-r: a large ifTable walk on gear that's slow to answer the first
     * SNMP packet must retry, not return empty and store 0 interfaces.
     *
     * @return list<string>
     */
    private function snmpWalkCommand(Device $device, string $oid): array
    {
        if ($device->snmp_version === 'v3') {
            return [
                'snmpwalk', '-v3', '-t', '3', '-r', '3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                $device->ip_address, $oid,
            ];
        }

        return ['snmpwalk', '-v2c', '-t', '3', '-r', '3', '-c', (string) $device->snmp_community, $device->ip_address, $oid];
    }
}
