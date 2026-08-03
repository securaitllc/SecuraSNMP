<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\MacPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class MacPollDevice extends Command
{
    protected $signature = 'mac:poll-device {id}';

    protected $description = 'Learn one device\'s MAC forwarding table over SNMP into the mac_addresses history.';

    public function handle(): int
    {
        $device = Device::find($this->argument('id'));
        if (! $device) {
            return self::SUCCESS;
        }

        // -On: numeric OIDs so the FDB index (vlan + 6 mac octets) parses
        // deterministically regardless of loaded MIBs.
        $walker = function (Device $device, string $oid): string {
            $process = new Process($this->snmpWalkCommand($device, $oid));
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException("snmpwalk failed for {$device->ip_address} ({$oid})");
            }

            return $process->getOutput();
        };

        try {
            (new MacPoller($walker))->poll($device);
        } catch (Throwable $e) {
            Log::error("MAC poll failed for device {$device->id}: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function snmpWalkCommand(Device $device, string $oid): array
    {
        if ($device->snmp_version === 'v3') {
            return [
                'snmpwalk', '-On', '-v3', '-t', '3', '-r', '3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                $device->ip_address, $oid,
            ];
        }

        return ['snmpwalk', '-On', '-v2c', '-t', '3', '-r', '3', '-c', (string) $device->snmp_community, $device->ip_address, $oid];
    }
}
