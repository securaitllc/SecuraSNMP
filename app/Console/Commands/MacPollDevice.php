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
    protected $signature = 'mac:poll-device {id} {--dump : Print the raw SNMP + parsed MACs and exit without writing}';

    protected $description = 'Learn one device\'s MAC forwarding table over SNMP into the mac_addresses history.';

    public function handle(): int
    {
        $device = Device::find($this->argument('id'));
        if (! $device) {
            $this->error('device not found');

            return self::SUCCESS;
        }

        if ($this->option('dump')) {
            return $this->dump($device);
        }

        try {
            (new MacPoller($this->walkerFor()))->poll($device);
        } catch (Throwable $e) {
            Log::error("MAC poll failed for device {$device->id}: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }

    /** -On: numeric OIDs so the FDB index (vlan + 6 mac octets) parses deterministically. */
    private function walkerFor(): callable
    {
        return function (Device $device, string $oid): string {
            $process = new Process($this->snmpWalkCommand($device, $oid));
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException("snmpwalk failed for {$device->ip_address} ({$oid}): ".trim($process->getErrorOutput()));
            }

            return $process->getOutput();
        };
    }

    /** Verify against a real switch: raw SNMP for each candidate FDB table + what the parser got. No writes. */
    private function dump(Device $device): int
    {
        $walker = $this->walkerFor();
        $probes = [
            'dot1qTpFdbPort (Q-BRIDGE, current gear)  .1.3.6.1.2.1.17.7.1.2.2.1.2' => '.1.3.6.1.2.1.17.7.1.2.2.1.2',
            'dot1dTpFdbPort (older BRIDGE-MIB)        .1.3.6.1.2.1.17.4.3.1.2' => '.1.3.6.1.2.1.17.4.3.1.2',
            'dot1dBasePortIfIndex                     .1.3.6.1.2.1.17.1.4.1.2' => '.1.3.6.1.2.1.17.1.4.1.2',
            // Juniper-specific — real per-MAC VLAN tag (shared-VLAN-learning gear).
            'jnxExVlanTag (vlan-index → 802.1Q tag)   .1.3.6.1.4.1.2636.3.40.1.5.1.5.1.5' => '.1.3.6.1.4.1.2636.3.40.1.5.1.5.1.5',
            'jnxL2aldMacDb (L2ALD MAC table)          .1.3.6.1.4.1.2636.3.48.1.3.1.1' => '.1.3.6.1.4.1.2636.3.48.1.3.1.1',
            'jnxExVlanMac (EX MAC-by-vlan table)      .1.3.6.1.4.1.2636.3.40.1.5.1.7.1' => '.1.3.6.1.4.1.2636.3.40.1.5.1.7.1',
        ];

        foreach ($probes as $label => $oid) {
            $this->line("== {$label} ==");
            try {
                $lines = array_values(array_filter(explode("\n", trim($walker($device, $oid)))));
            } catch (Throwable $e) {
                $this->error('   '.$e->getMessage());

                continue;
            }
            $this->line('   lines returned: '.count($lines));
            foreach (array_slice($lines, 0, 6) as $l) {
                $this->line('   '.$l);
            }
        }

        try {
            $parsed = MacPoller::parseFdb($walker($device, '.1.3.6.1.2.1.17.7.1.2.2.1.2'));
            $this->line('== parseFdb() extracted '.count($parsed).' MACs ==');
            foreach (array_slice($parsed, 0, 8) as $p) {
                $this->line("   vlan {$p['vlan']}  {$p['mac']}  bridgePort {$p['port']}");
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
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
