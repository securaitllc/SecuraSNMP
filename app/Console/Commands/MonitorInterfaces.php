<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\InterfacePoller;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MonitorInterfaces extends Command
{
    use RunsPollLoop;

    protected $signature = 'interfaces:monitor';

    protected $description = "Continuously polls each SNMP-credentialed device's interfaces and records status/discard changes.";

    public function handle(): int
    {
        $poller = new InterfacePoller(function (Device $device, string $oid): string {
            $process = new Process($this->buildSnmpWalkCommand($device, $oid));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    "snmpwalk failed for device {$device->ip_address} (OID: {$oid}): " .
                    ($process->getErrorOutput() ?: "Exit code: " . $process->getExitCode())
                );
            }

            return $process->getOutput();
        });

        $interval = max(30, (int) config('monitoring.interface_interval'));
        $this->info("Interface monitor started, polling every {$interval}s.");

        $this->pollForever('interfaces', $interval, fn () => $poller->pollAll());
    }

    private function buildSnmpWalkCommand(Device $device, string $oid): array
    {
        // Bounded -t/-r (matches HealthPollDevice): a large ifTable walk on gear that
        // drops SNMP under load must retry a lost packet, not fail the whole device.
        // Without this a single mid-walk timeout threw and left the device at 0 ports.
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
