<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\LldpCollector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DiscoverLldp extends Command
{
    use RunsPollLoop;

    protected $signature = 'lldp:discover';

    protected $description = 'Discovers physical adjacencies over LLDP so the topology draws real links.';

    public function handle(): int
    {
        $collector = new LldpCollector(function (Device $device, string $oid): string {
            $process = new Process($this->buildCommand($device, $oid));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(
                    "snmpwalk (LLDP) failed for {$device->ip_address} (OID {$oid}): ".
                    ($process->getErrorOutput() ?: 'exit '.$process->getExitCode())
                );
            }

            return $process->getOutput();
        });

        $this->info('LLDP discovery started, running every 10 minutes.');

        $this->pollForever('lldp', 600, fn () => $collector->discoverAll());
    }

    /**
     * @return list<string>
     */
    private function buildCommand(Device $device, string $oid): array
    {
        // -On forces numeric OIDs so the row index parses deterministically.
        if ($device->snmp_version === 'v3') {
            return [
                'snmpwalk', '-On', '-v3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                $device->ip_address, $oid,
            ];
        }

        return ['snmpwalk', '-On', '-v2c', '-c', (string) $device->snmp_community, $device->ip_address, $oid];
    }
}
