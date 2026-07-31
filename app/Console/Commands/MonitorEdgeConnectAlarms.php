<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\EdgeConnectAlarmPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Polls Silver Peak EdgeConnect active alarms on their OWN fast cadence, separate
 * from the 5-minute health poll (CPU/memory/identity). Alarms are the
 * operational priority — a NOC needs a raised alarm to appear and a cleared alarm
 * to disappear quickly — and the alarm-table walk is light (a handful of OIDs),
 * so it can run far more often than the heavy health poll.
 */
class MonitorEdgeConnectAlarms extends Command
{
    use RunsPollLoop;

    protected $signature = 'edgeconnect:alarms';

    protected $description = "Polls Silver Peak EdgeConnect active alarms on a fast cadence and reconciles them into DeviceAlarm.";

    public function handle(): int
    {
        $interval = max(30, (int) config('monitoring.edgeconnect_alarm_interval'));

        $walker = function (Device $device, string $oid): string {
            $process = new Process($this->buildSnmpWalkCommand($device, $oid));
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        };

        $poller = new EdgeConnectAlarmPoller($walker);

        $this->info("EdgeConnect alarm monitor started, polling every {$interval}s.");

        $this->pollForever('ec-alarms', $interval, function () use ($poller): void {
            Device::where('vendor', 'silverpeak')
                ->where('status', 'active')
                ->whereNotNull('snmp_community')
                ->each(function (Device $device) use ($poller): void {
                    // Isolate each appliance — one that stalls SNMP must not stop
                    // the others' alarms being raised or cleared.
                    try {
                        $poller->poll($device);
                    } catch (\Throwable $e) {
                        Log::error("EdgeConnect alarm poll failed for device {$device->id}: ".$e->getMessage());
                    }
                });
        });
    }

    private function buildSnmpWalkCommand(Device $device, string $oid): array
    {
        // Bound each walk (-t timeout, -r retries) so a device that answers ping
        // but stalls on SNMP can never hang the loop.
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
