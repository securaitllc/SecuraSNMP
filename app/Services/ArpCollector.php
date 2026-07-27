<?php

namespace App\Services;

use App\Models\Device;
use App\Models\LldpNeighbor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves endpoint MAC addresses from the SD-WAN edge's ARP table.
 *
 * LLDP cannot supply a MAC for every endpoint class. An access point advertises
 * lldpRemChassisId with subtype 4 (macAddress) and we get one for free; a Mitel
 * handset advertises subtype 5 (networkAddress), so its chassis id carries an IPv4
 * address instead. No amount of LLDP parsing produces a MAC for the handsets — and
 * they are the class an operator most often needs to trace, because a MAC-learning
 * log names a MAC and nothing else.
 *
 * The edge appliance already knows the mapping: its ARP table is IP to MAC for
 * every host that has talked on the subnet. LLDP gives the port and the IP, ARP
 * gives the MAC for that IP, and the two join on the address.
 *
 * Standard MIB-II (ipNetToMediaPhysAddress) rather than anything vendor-specific,
 * using credentials the poller already holds on devices it already polls.
 */
class ArpCollector
{
    /** ipNetToMediaPhysAddress — index is ifIndex.a.b.c.d, value is the MAC. */
    private const OID_ARP = '.1.3.6.1.2.1.4.22.1.2';

    /**
     * @param  callable(Device, string): string  $walker  Raw `snmpwalk -On` stdout for an OID.
     */
    public function __construct(private $walker)
    {
    }

    /**
     * Walk every SD-WAN edge and fill in the MACs it can resolve.
     *
     * Scoped to the edges because they are the layer-3 gateways holding ARP for the
     * site's subnets; walking every switch as well would multiply SNMP load for
     * tables that are mostly empty on an access switch.
     */
    public function resolveAll(): void
    {
        Device::where('role', 'edgeconnect')
            ->whereNotNull('snmp_version')
            ->get()
            ->each(function (Device $device) {
                try {
                    $this->resolve($device);
                } catch (Throwable $e) {
                    Log::warning("ARP resolve failed for device {$device->id}: {$e->getMessage()}");
                }
            });
    }

    /** @return int Number of neighbour rows given a MAC. */
    public function resolve(Device $device): int
    {
        $arp = $this->parse(($this->walker)($device, self::OID_ARP));

        if ($arp === []) {
            return 0;
        }

        // Only rows still missing a MAC. An endpoint that advertised its own MAC over
        // LLDP keeps it: the appliance's ARP entry may be a different interface of the
        // same device, and the endpoint's own claim about itself is the better source.
        //
        // Scoped to this edge's own site. Site LAN ranges repeat across a fleet, so an
        // unscoped join would confidently stamp one site's phone with another site's
        // MAC — a wrong answer is worse here than an empty column, because the whole
        // point is tracing an endpoint back to a port.
        $pending = LldpNeighbor::whereNull('remote_mac')
            ->whereNotNull('remote_mgmt_addr')
            ->whereIn('device_id', $device->site_id
                ? Device::where('site_id', $device->site_id)->pluck('id')
                : [$device->id])
            ->get(['id', 'remote_mgmt_addr']);

        $filled = 0;
        foreach ($pending as $neighbor) {
            $mac = $arp[$neighbor->remote_mgmt_addr] ?? null;
            if ($mac === null) {
                continue;
            }

            $neighbor->updateQuietly(['remote_mac' => $mac]);
            $filled++;
        }

        return $filled;
    }

    /**
     * Parse the walk into address => MAC.
     *
     * The index tail is the IPv4 address and the value is six hex octets, rendered
     * space-separated by snmpwalk when no MIBs are loaded — which is the production
     * reality here.
     *
     * @return array<string, string>
     */
    private function parse(string $output): array
    {
        $out = [];

        foreach (explode("\n", $output) as $line) {
            // The type label ("Hex-STRING:") is optional, but it must contain a letter
            // and be followed by whitespace — otherwise the first octet of a
            // colon-separated MAC looks like a label and gets eaten as one.
            if (! preg_match('/4\.22\.1\.2\.\d+\.((?:\d+\.){3}\d+)\s*=\s*(?:[\w-]*[A-Za-z][\w-]*:\s+)?([0-9A-Fa-f\s:]+)$/', trim($line), $m)) {
                continue;
            }

            $ip = $m[1];
            $octets = preg_split('/[\s:]+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($octets) !== 6) {
                continue;
            }

            foreach ($octets as $octet) {
                if (! preg_match('/^[0-9A-Fa-f]{1,2}$/', $octet)) {
                    continue 2;
                }
            }

            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $out[$ip] = strtoupper(implode(':', array_map(
                fn ($o) => str_pad($o, 2, '0', STR_PAD_LEFT),
                $octets,
            )));
        }

        return $out;
    }

    /** Production wiring: the same bounded snmpwalk the other pollers use. */
    public static function forProduction(callable $walker): self
    {
        return new self($walker);
    }
}
