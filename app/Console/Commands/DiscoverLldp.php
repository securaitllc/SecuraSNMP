<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\LldpCollector;
use App\Services\LldpSshCollector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DiscoverLldp extends Command
{
    use RunsPollLoop;

    protected $signature = 'lldp:discover';

    protected $description = 'Discovers physical adjacencies over LLDP so the topology draws real links.';

    public function handle(): int
    {
        $collector = new LldpCollector(fn (Device $device, string $oid): string => $this->walk($device, $oid, 'LLDP'));

        // ARP (IP↔MAC, and the handset-MAC back-fill LLDP can't get) now runs on its own
        // faster loop — see ArpPoll (`arp:poll`) — so endpoint IPs stay fresh instead of
        // waiting on the 10-minute LLDP cadence.

        // EdgeConnect appliances don't answer the LLDP-MIB over SNMP, so read their
        // neighbors over SSH (`show lldp neighbors`) in the same sweep — per-device
        // try/catch inside, so one unreachable appliance can't stall discovery.
        $sshLldp = LldpSshCollector::forProduction();

        $this->info('LLDP discovery started, running every 10 minutes.');

        $this->pollForever('lldp', 600, function () use ($collector, $sshLldp) {
            $collector->discoverAll();
            $sshLldp->pollAll();
        });
    }

    private function walk(Device $device, string $oid, string $label): string
    {
        $process = new Process($this->buildCommand($device, $oid));
        $process->setTimeout(20); // hard kill so one slow device can't stall discovery
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "snmpwalk ({$label}) failed for {$device->ip_address} (OID {$oid}): ".
                ($process->getErrorOutput() ?: 'exit '.$process->getExitCode())
            );
        }

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function buildCommand(Device $device, string $oid): array
    {
        // -On forces numeric OIDs so the row index parses deterministically.
        // -t 3 -r 3 bounds each SNMP request (matches the other pollers) so a
        // device that drops responses can't stall the walk to the 60s ceiling.
        if ($device->snmp_version === 'v3') {
            return [
                'snmpwalk', '-On', '-t', '3', '-r', '3', '-v3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                $device->ip_address, $oid,
            ];
        }

        return ['snmpwalk', '-On', '-t', '3', '-r', '3', '-v2c', '-c', (string) $device->snmp_community, $device->ip_address, $oid];
    }
}
