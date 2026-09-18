<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Finds out what an EdgeConnect will actually tell us about a WAN port's link.
 *
 * A carrier had its handoff hardcoded to 100/full while our side was auto, which
 * autonegotiates down to HALF duplex — late collisions, FCS errors, and loss that
 * grows with traffic. Nodus could not have seen it: ECOS returns nothing for
 * dot3StatsDuplexStatus and zero for ifInErrors/ifOutErrors on every interface of
 * every appliance, so the two counters that carry the entire signature of that
 * fault are unavailable, and the port renders as healthy.
 *
 * Before building a check on any of these, read what a real appliance answers.
 * That is the lesson the FortiGate HA false positive already charged us for.
 *
 *   php artisan edgeconnect:probe-interface 232
 *   php artisan edgeconnect:probe-interface 232 --oid=.1.3.6.1.4.1.23867.3.1.2
 *
 * "(no answer)" means that OID cannot carry a check on this build.
 */
class ProbeEdgeConnectInterface extends Command
{
    protected $signature = 'edgeconnect:probe-interface
        {id : Device id of an EdgeConnect appliance}
        {--oid= : Walk one arbitrary OID instead of the standard set}
        {--rows=14 : Max rows to show per OID}';

    protected $description = 'Show what an EdgeConnect answers for link speed, duplex and error counters.';

    /** @var array<string, string> label => OID */
    private const OIDS = [
        'ifName' => '.1.3.6.1.2.1.31.1.1.1.1',
        'ifOperStatus' => '.1.3.6.1.2.1.2.2.1.8',
        'ifSpeed (bps)' => '.1.3.6.1.2.1.2.2.1.5',
        'ifHighSpeed (Mbps)' => '.1.3.6.1.2.1.31.1.1.1.15',
        'ifInErrors' => '.1.3.6.1.2.1.2.2.1.14',
        'ifOutErrors' => '.1.3.6.1.2.1.2.2.1.20',
        'ifInDiscards' => '.1.3.6.1.2.1.2.2.1.13',
        'ifOutDiscards' => '.1.3.6.1.2.1.2.2.1.19',

        // EtherLike-MIB — the duplex and collision counters. If ANY of these answer,
        // a duplex-mismatch check becomes possible over SNMP alone.
        'dot3StatsDuplexStatus' => '.1.3.6.1.2.1.10.7.2.1.19',
        'dot3StatsLateCollisions' => '.1.3.6.1.2.1.10.7.2.1.8',
        'dot3StatsSingleCollision' => '.1.3.6.1.2.1.10.7.2.1.4',
        'dot3StatsFCSErrors' => '.1.3.6.1.2.1.10.7.2.1.3',
        'dot3StatsAlignmentErrors' => '.1.3.6.1.2.1.10.7.2.1.2',
        'dot3StatsCarrierSenseErrors' => '.1.3.6.1.2.1.10.7.2.1.11',
        'dot3 table root' => '.1.3.6.1.2.1.10.7.2',

        // Silver Peak's own enterprise tree. ECOS does not populate ENTITY-MIB and may
        // well keep link state somewhere of its own; walking the root is how we find
        // out rather than guess at column numbers.
        'SILVERPEAK root .23867.3.1' => '.1.3.6.1.4.1.23867.3.1',
    ];

    public function handle(): int
    {
        $device = Device::find($this->argument('id'));

        if (! $device) {
            $this->error('No such device.');

            return self::FAILURE;
        }

        $this->info("── {$device->name}  ({$device->ip_address})  vendor={$device->vendor}");
        $this->line('');

        $oids = $this->option('oid')
            ? ['(custom)' => (string) $this->option('oid')]
            : self::OIDS;

        foreach ($oids as $label => $oid) {
            $lines = array_values(array_filter(
                explode("\n", trim($this->walk($device, $oid))),
                fn (string $l) => trim($l) !== ''
                    && stripos($l, 'No Such') === false
                    && stripos($l, 'Timeout') === false,
            ));

            if ($lines === []) {
                $this->line(sprintf('  %-30s (no answer)', $label));

                continue;
            }

            $max = max(1, (int) $this->option('rows'));
            $this->line(sprintf('  %-30s %s', $label, mb_strimwidth(trim($lines[0]), 0, 86, '…')));

            foreach (array_slice($lines, 1, $max - 1) as $line) {
                $this->line(sprintf('  %-30s %s', '', mb_strimwidth(trim($line), 0, 86, '…')));
            }

            if (count($lines) > $max) {
                $this->line(sprintf('  %-30s … %d more rows', '', count($lines) - $max));
            }
        }

        $this->line('');
        $this->comment('"(no answer)" = that OID cannot carry a check on this build.');
        $this->comment('A duplex check needs dot3StatsDuplexStatus, or late collisions / FCS errors, to answer.');

        return self::SUCCESS;
    }

    private function walk(Device $device, string $oid): string
    {
        $command = $device->snmp_version === 'v3'
            ? [
                'snmpwalk', '-v3', '-t', '3', '-r', '2', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                $device->ip_address, $oid,
            ]
            : ['snmpwalk', '-v2c', '-t', '3', '-r', '2', '-c', (string) $device->snmp_community, $device->ip_address, $oid];

        $process = new Process($command);
        $process->setTimeout(45);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : (string) $process->getErrorOutput();
    }
}
