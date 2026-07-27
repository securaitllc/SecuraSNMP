<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\HealthPoller;
use App\Services\SnmpIdentityPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class MonitorHealth extends Command
{
    use RunsPollLoop;

    protected $signature = 'health:monitor';

    protected $description = "Continuously polls each SNMP-credentialed device's CPU, memory, temperature and environmental sensors.";

    public function handle(): int
    {
        $walker = function (Device $device, string $oid): string {
            $process = new Process($this->buildSnmpWalkCommand($device, $oid));
            $process->run();

            // A device that doesn't expose a table just yields no rows — treat a
            // failed walk as empty rather than aborting the whole poll cycle.
            return $process->isSuccessful() ? $process->getOutput() : '';
        };

        $poller = new HealthPoller($walker);
        $identityPoller = new SnmpIdentityPoller($walker);

        $this->info('Health monitor started, polling every 5 minutes.');

        $this->pollForever('health', 300, function () use ($poller, $identityPoller): void {
            Device::whereNotNull('snmp_community')
                ->orWhereNotNull('snmp_v3_username')
                ->each(function (Device $device) use ($poller, $identityPoller): void {
                    // Isolate each appliance: a single device that throws (or a
                    // half-rebooted SD-WAN whose SNMP misbehaves) must NOT abort the
                    // whole cycle — otherwise every other device stops getting its
                    // alarms raised or cleared until the next loop, which is exactly
                    // how stale/missed alarms happened in production.
                    try {
                        // Fill in model / serial / OS version over SNMP (once).
                        $identityPoller->poll($device);
                        $poller->poll($device);
                        // NOTE: EdgeConnect alarm polling runs in its own faster
                        // loop (edgeconnect:alarms) so raise/clear latency isn't tied
                        // to this 5-minute health cadence.
                    } catch (\Throwable $e) {
                        Log::error("Health poll failed for device {$device->id}: ".$e->getMessage());
                    }
                });
        });
    }

    private function buildSnmpWalkCommand(Device $device, string $oid): array
    {
        // Bound each walk (-t timeout, -r retries) so a device that answers ping
        // but stalls on SNMP can never hang the shared health loop.
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
