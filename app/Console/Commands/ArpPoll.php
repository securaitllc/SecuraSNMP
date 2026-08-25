<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\ArpCollector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Dedicated ARP poller: reads each SD-WAN edge's ARP table (IP -> MAC) and persists it,
 * so the MAC tool can show an endpoint's IP + site and trace by IP. Split out of LLDP
 * discovery (which runs every 10 min) onto its own faster loop — ARP changes far more
 * often than physical adjacency, and a stale IP is worse than a stale link. It also
 * back-fills LLDP neighbour MACs (handsets advertise an address, not a MAC).
 *
 * One bounded snmpwalk per edge per cycle; scoped to edgeconnect devices only (the L3
 * gateways that hold ARP for the site's subnets).
 */
class ArpPoll extends Command
{
    use RunsPollLoop;

    protected $signature = 'arp:poll {--once : Run a single ARP sweep and exit — populate now + report edges swept / rows / errors}';

    protected $description = 'Polls SD-WAN edge ARP tables (IP↔MAC) so the MAC tool resolves endpoints by IP and site.';

    public function handle(): int
    {
        $arp = new ArpCollector(fn (Device $device, string $oid): string => $this->walk($device, $oid));

        // One-shot: populate immediately and show what happened (for diagnosis / a manual refresh).
        if ($this->option('once')) {
            $edges = Device::where('role', 'edgeconnect')->whereNotNull('snmp_version')->get();
            $before = \App\Models\ArpEntry::count();
            $filled = 0;
            $errors = 0;
            foreach ($edges as $edge) {
                try {
                    $arp->resolve($edge);
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  {$edge->name} ({$edge->ip_address}): {$e->getMessage()}");
                }
            }
            $after = \App\Models\ArpEntry::count();
            $this->info("Swept {$edges->count()} edge(s), {$errors} error(s). arp_entries: {$before} → {$after}.");

            return self::SUCCESS;
        }

        $interval = max(60, (int) env('POLL_ARP_SECONDS', 180));
        $this->info("ARP polling started, running every {$interval}s.");
        $this->pollForever('arp', $interval, function () use ($arp) {
            $arp->resolveAll();
        });

        return self::SUCCESS;
    }

    private function walk(Device $device, string $oid): string
    {
        $process = new Process($this->buildCommand($device, $oid));
        $process->setTimeout(20); // hard kill so one slow edge can't stall the sweep
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "snmpwalk (ARP) failed for {$device->ip_address} (OID {$oid}): ".
                ($process->getErrorOutput() ?: 'exit '.$process->getExitCode())
            );
        }

        return $process->getOutput();
    }

    /** @return list<string> */
    private function buildCommand(Device $device, string $oid): array
    {
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
