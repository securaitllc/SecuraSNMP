<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceAddress;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects the IP addresses configured on each device's own interfaces.
 *
 * The gap this fills: the system knew a device's single management address and, from
 * ARP, the neighbours it had spoken to — but nothing recorded which address sits on
 * which port. For an HA appliance pair or a firewall holding several public addresses
 * out of a /27, that is the difference between allocating the next address safely and
 * causing an outage by handing out one already in use.
 *
 * Standard MIB-II ipAddrTable, so it works across Silver Peak, FortiGate and Juniper
 * without vendor-specific handling. The table is indexed BY the address itself:
 *
 *   .1.3.6.1.2.1.4.20.1.2.10.11.6.248 = INTEGER: 5              (address -> ifIndex)
 *   .1.3.6.1.2.1.4.20.1.3.10.11.6.248 = IpAddress: 255.255.255.0 (address -> netmask)
 *
 * so the ifIndex walk alone yields every address, and the netmask walk gives each one
 * its prefix. ipAdEntAddr (.1) is redundant and is not walked.
 */
class InterfaceAddressCollector
{
    /** ipAdEntIfIndex — index is the address, value is the interface it sits on. */
    private const OID_IFINDEX = '.1.3.6.1.2.1.4.20.1.2';

    /** ipAdEntNetMask — index is the address, value is its mask. */
    private const OID_NETMASK = '.1.3.6.1.2.1.4.20.1.3';

    /**
     * Blocks that are internal to the platform rather than allocated addressing.
     *
     * Juniper puts 128.0.0.1/2 on its internal bridge interface (bme0) — the same
     * address on all 167 switches here, with a /2 prefix no real allocation would ever
     * carry. It is control-plane plumbing and belongs in an IPAM no more than the
     * loopback does; left in, it was 805 rows of noise across the address map.
     */
    private const INTERNAL_BLOCKS = ['128.0.'];

    /** @param callable(Device, string): string $walker Raw `snmpwalk -On` stdout for an OID. */
    public function __construct(private $walker)
    {
    }

    /**
     * Sweep every SNMP-capable device.
     *
     * Deliberately not scoped to gateways the way ARP collection is. A switch's
     * management address and its SVIs occupy real addresses too, and an IPAM that
     * omitted them would report space as free when it is not — the one failure mode
     * this whole feature exists to prevent.
     *
     * @return array{devices: int, addresses: int, errors: int}
     */
    public function collectAll(): array
    {
        $devices = 0;
        $addresses = 0;
        $errors = 0;

        Device::whereNotNull('snmp_version')
            ->orderBy('id')
            ->chunk(50, function ($chunk) use (&$devices, &$addresses, &$errors) {
                foreach ($chunk as $device) {
                    try {
                        $addresses += $this->collect($device);
                        $devices++;
                    } catch (Throwable $e) {
                        // One unreachable box must never stop the sweep.
                        $errors++;
                        Log::warning("Interface address collect failed for device {$device->id}: {$e->getMessage()}");
                    }
                }
            });

        return ['devices' => $devices, 'addresses' => $addresses, 'errors' => $errors];
    }

    /** @return int Number of addresses recorded for this device. */
    public function collect(Device $device): int
    {
        $ifIndexes = $this->parseIndexed(($this->walker)($device, self::OID_IFINDEX), self::OID_IFINDEX);

        if ($ifIndexes === []) {
            // An empty walk is no evidence the device has no addresses — it usually
            // means it did not answer. Leaving what we already hold is safer than
            // deleting it and reporting the space as free.
            return 0;
        }

        $masks = $this->parseIndexed(($this->walker)($device, self::OID_NETMASK), self::OID_NETMASK);

        $interfaces = DeviceInterface::where('device_id', $device->id)
            ->pluck('id', 'if_index');

        $now = now();
        $seen = [];

        foreach ($ifIndexes as $ip => $ifIndex) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            // The loopback tells us nothing about allocated space, and neither does a
            // vendor's internal control-plane address.
            if (str_starts_with($ip, '127.') || self::isInternal($ip)) {
                continue;
            }

            $mask = $masks[$ip] ?? null;
            $prefix = $mask ? self::maskToPrefix($mask) : null;
            $ifIndex = is_numeric($ifIndex) ? (int) $ifIndex : null;

            InterfaceAddress::updateOrCreate(
                ['device_id' => $device->id, 'ip' => $ip],
                [
                    'device_interface_id' => $ifIndex !== null ? ($interfaces[$ifIndex] ?? null) : null,
                    'if_index' => $ifIndex,
                    'prefix_len' => $prefix,
                    'netmask' => $mask,
                    'is_public' => self::isPublic($ip),
                    'first_seen_at' => now(),   // kept by updateOrCreate on an existing row
                    'last_seen_at' => $now,
                ]
            );
            $seen[] = $ip;
        }

        // An address the device no longer reports has been removed from its config, so
        // it is genuinely free again — but only prune when the walk actually answered.
        if ($seen !== []) {
            InterfaceAddress::where('device_id', $device->id)->whereNotIn('ip', $seen)->delete();
        }

        return count($seen);
    }

    /**
     * Parse an `snmpwalk -On` dump whose OID index IS an IPv4 address.
     *
     * The index is the FIRST four sub-identifiers after the column OID, and only those.
     * Some agents append a further component — a Juniper SRX emits
     * `...4.20.1.2.4.18.134.165.1`, one longer than the Silver Peak's
     * `...4.20.1.2.4.18.134.162` — so reading the last four instead of the first four
     * silently shifts every octet: 4.18.134.165 was recorded as 18.134.165.1, which
     * also flipped private addresses to "public". Anchor on the prefix, never the end.
     *
     * @return array<string, string> address => value
     */
    private function parseIndexed(string $output, string $baseOid): array
    {
        $base = ltrim($baseOid, '.');
        $out = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$oid, $value] = explode('=', $line, 2);

            $rest = ltrim(trim($oid), '.');
            if (! str_starts_with($rest, $base.'.')) {
                continue;
            }
            $index = substr($rest, strlen($base) + 1);

            if (! preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(?:\.|$)/', $index, $m)) {
                continue;
            }

            // "INTEGER: 5" / "IpAddress: 255.255.255.0" / a bare value when MIBs are absent.
            $v = trim($value);
            if (str_contains($v, ':')) {
                $v = trim(substr($v, strpos($v, ':') + 1));
            }
            $v = trim($v, "\" \t");

            if ($v !== '') {
                $out[$m[1]] = $v;
            }
        }

        return $out;
    }

    /** Is this a vendor's internal control-plane address rather than real addressing? */
    public static function isInternal(string $ip): bool
    {
        foreach (self::INTERNAL_BLOCKS as $block) {
            if (str_starts_with($ip, $block)) {
                return true;
            }
        }

        return false;
    }

    /** 255.255.255.224 => 27. Returns null for a mask that is not contiguous. */
    public static function maskToPrefix(string $mask): ?int
    {
        $long = ip2long($mask);
        if ($long === false) {
            return null;
        }
        $bits = sprintf('%032b', $long);

        return preg_match('/^1*0*$/', $bits) ? substr_count($bits, '1') : null;
    }

    /** Routable on the internet — not RFC1918, loopback, link-local or multicast. */
    public static function isPublic(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /** Production wiring: the same bounded snmpwalk the other pollers use. */
    public static function forProduction(callable $walker): self
    {
        return new self($walker);
    }
}
