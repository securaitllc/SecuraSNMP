<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\FortiGateAlarmPoller;
use App\Services\FortiGateLogAlarms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Derives firewall alarms from FortiGate state on its own cadence.
 *
 * Separate from the 5-minute health poll for the same reason the EdgeConnect alarm
 * loop is: a NOC needs a raised alarm to appear and a cleared one to disappear
 * quickly, and these walks are light — a few scalars plus two small tables.
 */
class MonitorFortiGateAlarms extends Command
{
    use RunsPollLoop;

    protected $signature = 'fortigate:alarms';

    protected $description = 'Polls FortiGate firewalls (VPN tunnels, HA sync, memory/disk/CPU) and reconciles them into DeviceAlarm.';

    public function handle(): int
    {
        $interval = max(30, (int) config('monitoring.fortigate_alarm_interval'));

        $walker = function (Device $device, string $oid): string {
            $process = new Process($this->buildSnmpWalkCommand($device, $oid));
            $process->setTimeout(30); // hard kill — never wait the 60s default on a wedged walk
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        };

        $poller = new FortiGateAlarmPoller($walker);

        $this->info("FortiGate alarm monitor started, polling every {$interval}s.");

        $this->pollForever('fg-alarms', $interval, function () use ($poller): void {
            Device::where('vendor', 'fortigate')
                ->where('status', 'active')
                ->whereNotNull('snmp_community')
                ->each(function (Device $device) use ($poller): void {
                    // Isolate each firewall — one stalling on SNMP must not stop the
                    // others' alarms being raised or cleared.
                    try {
                        $poller->poll($device);
                    } catch (\Throwable $e) {
                        Log::error("FortiGate alarm poll failed for device {$device->id}: ".$e->getMessage());
                    }

                    $this->beat();
                });

            // Log-derived alarms are events, not states — nothing goes missing from a
            // table to clear them. Silence is the only signal there is, so they clear
            // once they stop recurring.
            try {
                FortiGateLogAlarms::clearQuiet();
            } catch (\Throwable $e) {
                Log::error('FortiGate log-alarm sweep failed: '.$e->getMessage());
            }
        });
    }

    /** @return list<string> */
    private function buildSnmpWalkCommand(Device $device, string $oid): array
    {
        // Bound each walk (-t timeout, -r retries) so a firewall that answers ping
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
