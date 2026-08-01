<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\HealthPoller;
use App\Services\SnmpIdentityPoller;
use App\Services\VcMemberPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Polls the identity + health of ONE device. It exists so health:monitor can run
 * many of these concurrently (a bounded subprocess pool) instead of walking 270
 * appliances one at a time — a sequential SNMP sweep took 30-40 minutes, which left
 * devices at the end (the EdgeConnects) starved of identity/health updates.
 *
 * Each invocation is isolated: one appliance's misbehaving SNMP can't affect another.
 */
class HealthPollDevice extends Command
{
    protected $signature = 'health:poll-device {id}';

    protected $description = 'Poll identity + health for a single device (spawned concurrently by health:monitor).';

    public function handle(): int
    {
        $device = Device::find($this->argument('id'));
        if (! $device) {
            return self::SUCCESS;
        }

        $walker = function (Device $device, string $oid): string {
            $process = new Process($this->snmpWalkCommand($device, $oid));
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        };

        try {
            (new SnmpIdentityPoller($walker))->poll($device);
            (new HealthPoller($walker))->poll($device);
            (new VcMemberPoller($walker))->poll($device);
        } catch (Throwable $e) {
            Log::error("Health poll failed for device {$device->id}: ".$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * Bounded snmpwalk (-t timeout, -r retries) so a device that answers ping but
     * stalls on SNMP can never hang the poll.
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
