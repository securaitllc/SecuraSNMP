<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LldpNeighbor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves endpoint MAC addresses from every layer-3 gateway's ARP table.
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
     * Walk every L3 gateway and fill in the MACs it can resolve.
     *
     * Scoped to every layer-3 gateway on the fleet, because only a gateway holds ARP
     * for the subnet it routes:
     *
     *  - the SD-WAN edges and the firewalls, by role. A FortiGate that serves DHCP +
     *    gateways a VLAN (e.g. VLAN 730 for Verkada at HQ) holds the only ARP for those
     *    hosts — the SD-WAN edge never sees them.
     *  - any switch that ROUTES, detected by it having an SVI (irb.N on Juniper ELS,
     *    vlan.N on older EX). This app has one `switch` role, so an HQ core and a
     *    branch access switch are indistinguishable by role — and the cores are the
     *    only ARP source for the HQ VLANs. 10.11.55.0/24 read as completely empty
     *    while FL0001-HQSWC03 held ~50 live hosts for it on irb.55. An unmeasured
     *    range must never render as an empty one.
     *
     * Access switches still cost nothing: they have no SVI, so they are not selected —
     * the test is a join on interfaces already polled, not an extra SNMP walk.
     */
    public function resolveAll(): void
    {
        Device::query()
            ->whereNotNull('snmp_version')
            ->where(function ($q) {
                $q->whereIn('role', ['edgeconnect', 'firewall'])
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('device_interfaces')
                        ->whereColumn('device_interfaces.device_id', 'devices.id')
                        ->where(fn ($w) => $w->where('if_name', 'like', 'irb.%')
                            ->orWhere('if_name', 'like', 'vlan.%')));
            })
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
        $entries = $this->parse(($this->walker)($device, self::OID_ARP));

        if ($entries === []) {
            return 0;
        }

        $this->persist($device, $entries);

        // The LLDP fill below only ever wants the MAC.
        $arp = array_map(fn (array $e) => $e['mac'], $entries);

        // Only rows still missing a MAC. An endpoint that advertised its own MAC over
        // LLDP keeps it: the appliance's ARP entry may be a different interface of the
        // same device, and the endpoint's own claim about itself is the better source.
        //
        // Scoped to this edge's own site. Site LAN ranges repeat across a fleet, so an
        // unscoped join would confidently stamp one site's phone with another site's
        // MAC — a wrong answer is worse here than an empty column, because the whole
        // point is tracing an endpoint back to a port.
        $pending = LldpNeighbor::present()
            ->whereNull('remote_mac')
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
     * Persist the edge's IP -> MAC table so an endpoint can be traced by IP and the IP
     * shows next to a learned MAC. Scoped to this edge (device_id) because the same
     * private IP repeats across sites with different MACs.
     *
     * The interface each neighbour was learned on is kept, not just the MAC. Without it
     * an entry from the WAN uplink is indistinguishable from one on a site LAN, which is
     * how every site's cable modem at 192.168.100.1 came to read as a LAN host.
     *
     * @param  array<string, array{mac: string, if_index: int}>  $entries  ip => neighbour
     */
    private function persist(Device $device, array $entries): void
    {
        // ifIndex is only a number until the interface poller's table names it. A gap
        // here leaves the name null — honest — rather than inventing one.
        $names = DeviceInterface::where('device_id', $device->id)
            ->whereNotNull('if_index')
            ->pluck('if_name', 'if_index')
            ->all();

        $now = now();
        $rows = [];
        foreach ($entries as $ip => $e) {
            $rows[] = [
                'device_id' => $device->id,
                'site_id' => $device->site_id,
                'ip' => $ip,
                'mac' => $e['mac'],
                'if_index' => $e['if_index'],
                'interface' => $names[$e['if_index']] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows === []) {
            return;
        }
        // Insert new IPs, refresh mac + last_seen for existing ones; first_seen is preserved.
        \App\Models\ArpEntry::upsert(
            $rows,
            ['device_id', 'ip'],
            ['mac', 'if_index', 'interface', 'site_id', 'last_seen_at', 'updated_at'],
        );
    }

    /**
     * Parse the walk into address => MAC.
     *
     * The index tail is the IPv4 address and the value is six hex octets, rendered
     * space-separated by snmpwalk when no MIBs are loaded — which is the production
     * reality here.
     *
     * The ifIndex is the FIRST index component and is captured, not discarded: it is
     * the only thing in this table that says whether a neighbour is on the LAN or on
     * the WAN uplink.
     *
     * @return array<string, array{mac: string, if_index: int}>
     */
    private function parse(string $output): array
    {
        $out = [];

        foreach (explode("\n", $output) as $line) {
            // The type label ("Hex-STRING:") is optional, but it must contain a letter
            // and be followed by whitespace — otherwise the first octet of a
            // colon-separated MAC looks like a label and gets eaten as one.
            if (! preg_match('/4\.22\.1\.2\.(\d+)\.((?:\d+\.){3}\d+)\s*=\s*(?:[\w-]*[A-Za-z][\w-]*:\s+)?([0-9A-Fa-f\s:]+)$/', trim($line), $m)) {
                continue;
            }

            $ifIndex = (int) $m[1];
            $ip = $m[2];
            $octets = preg_split('/[\s:]+/', trim($m[3]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

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

            $out[$ip] = [
                'mac' => strtoupper(implode(':', array_map(
                    fn ($o) => str_pad($o, 2, '0', STR_PAD_LEFT),
                    $octets,
                ))),
                'if_index' => $ifIndex,
            ];
        }

        return $out;
    }

    /** Production wiring: the same bounded snmpwalk the other pollers use. */
    public static function forProduction(callable $walker): self
    {
        return new self($walker);
    }
}
