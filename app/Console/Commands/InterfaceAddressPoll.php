<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Models\InterfaceAddress;
use App\Services\InterfaceAddressCollector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Records the IP addresses configured on every device's own interfaces (ipAddrTable).
 *
 * Answers the question no other poller could: which public address is on which WAN
 * port of the HA pair, and which of a /27 the firewalls have already taken. Without
 * it the only way to allocate a public address safely was to log into each box.
 *
 * Interface addressing changes rarely — this is configuration, not traffic — so the
 * default cycle is 15 minutes rather than the ARP poller's 180 seconds.
 */
class InterfaceAddressPoll extends Command
{
    use RunsPollLoop;

    protected $signature = 'addresses:poll
        {--once : Run a single sweep and exit, reporting devices swept and addresses found}
        {--device= : Sweep one device by id or name, for diagnosis}';

    protected $description = 'Polls each device\'s configured interface addresses (ipAddrTable) so allocated IPs are known.';

    public function handle(): int
    {
        $collector = new InterfaceAddressCollector(
            fn (Device $device, string $oid): string => $this->walk($device, $oid)
        );

        if ($one = $this->option('device')) {
            $device = Device::where('id', $one)->orWhere('name', $one)->first();
            if (! $device) {
                $this->error("No device matching '{$one}'.");

                return self::FAILURE;
            }
            $n = $collector->collect($device);
            $this->info("{$device->name}: {$n} address(es).");
            $this->table(
                ['Address', 'Prefix', 'Interface', 'Scope'],
                InterfaceAddress::where('device_id', $device->id)->with('interface')->get()
                    ->map(fn ($a) => [
                        $a->ip,
                        $a->prefix_len !== null ? '/'.$a->prefix_len : '—',
                        $a->interface?->if_name ?? ($a->if_index !== null ? 'ifIndex '.$a->if_index : '—'),
                        $a->is_public ? 'PUBLIC' : 'private',
                    ])->all()
            );

            return self::SUCCESS;
        }

        if ($this->option('once')) {
            $before = InterfaceAddress::count();
            $r = $collector->collectAll();
            $after = InterfaceAddress::count();
            $public = InterfaceAddress::where('is_public', true)->count();

            $this->info("Swept {$r['devices']} device(s), {$r['errors']} error(s). ".
                "interface_addresses: {$before} → {$after} ({$public} public).");

            return self::SUCCESS;
        }

        $interval = max(120, (int) env('POLL_ADDRESS_SECONDS', 900));
        $this->info("Interface address polling started, running every {$interval}s.");
        $this->pollForever('addresses', $interval, function () use ($collector) {
            $collector->collectAll();
        });

        return self::SUCCESS;
    }

    private function walk(Device $device, string $oid): string
    {
        $process = new Process($this->buildCommand($device, $oid));
        $process->setTimeout(20);  // hard kill so one slow box cannot stall the sweep
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "snmpwalk (addresses) failed for {$device->ip_address} (OID {$oid}): ".
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
