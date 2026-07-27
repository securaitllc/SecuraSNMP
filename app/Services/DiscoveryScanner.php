<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DiscoveredDevice;
use App\Models\DiscoveryScan;
use App\Models\Site;
use App\Models\SnmpCredential;

/**
 * Standardized subnet-based SNMP discovery.
 *
 * The scan mechanics (ICMP + snmpget) are injected as a probe callable so the
 * classification/dedup/site-mapping logic here stays deterministic and unit
 * testable — the same pattern MonitorInterfaces uses for InterfacePoller.
 */
class DiscoveryScanner
{
    /**
     * @param  callable(string $ip, SnmpCredential $cred): (array{sys_name?: ?string, sys_descr?: ?string, sys_object_id?: ?string, serial?: ?string}|null)  $probe
     *         Returns SNMP facts for a reachable, SNMP-speaking host, or null.
     */
    public function run(DiscoveryScan $scan, callable $probe): void
    {
        $hosts = self::expandSubnets($scan->subnets);

        $scan->update([
            'status' => 'running',
            'started_at' => now(),
            'hosts_total' => count($hosts),
            'hosts_responded' => 0,
            'devices_found' => 0,
        ]);

        $cred = $scan->credential;
        $responded = 0;
        $found = 0;

        foreach ($hosts as $ip) {
            $facts = $probe($ip, $cred);

            if ($facts === null) {
                continue;
            }

            $responded++;

            $role = self::classifyRole($ip);
            $vendor = self::guessVendor($facts['sys_descr'] ?? null, $facts['sys_object_id'] ?? null)
                ?? self::vendorForRole($role);
            $model = self::guessModel($facts['sys_descr'] ?? null, $vendor);
            $serial = $facts['serial'] ?? null;

            $matched = Device::where('ip_address', $ip)->first();
            if (! $matched && $serial) {
                $matched = Device::where('serial_number', $serial)->first();
            }

            DiscoveredDevice::create([
                'discovery_scan_id' => $scan->id,
                'ip_address' => $ip,
                'sys_name' => $facts['sys_name'] ?? null,
                'sys_descr' => $facts['sys_descr'] ?? null,
                'sys_object_id' => $facts['sys_object_id'] ?? null,
                'vendor' => $vendor,
                'model' => $model,
                'serial_number' => $serial,
                'suggested_role' => $role,
                'suggested_site_id' => self::siteFor($ip)->id,
                'matched_device_id' => $matched?->id,
                'status' => $matched ? 'existing' : 'new',
            ]);

            $found++;
        }

        $scan->update([
            'status' => 'completed',
            'hosts_responded' => $responded,
            'devices_found' => $found,
            'finished_at' => now(),
        ]);
    }

    /**
     * Expand a list of CIDRs to their usable host IPs (network and broadcast
     * excluded for prefixes shorter than /31). Invalid entries are skipped.
     *
     * @param  array<int, string>  $cidrs
     * @return array<int, string>
     */
    public static function expandSubnets(array $cidrs): array
    {
        $hosts = [];

        foreach ($cidrs as $cidr) {
            if (! str_contains((string) $cidr, '/')) {
                continue;
            }

            [$network, $prefix] = explode('/', (string) $cidr, 2);
            $prefix = (int) $prefix;

            if (! filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 0 || $prefix > 32) {
                continue;
            }

            $base = ip2long($network) & self::maskLong($prefix);
            $count = $prefix >= 31 ? (1 << (32 - $prefix)) : (1 << (32 - $prefix)) - 2;
            $first = $prefix >= 31 ? $base : $base + 1;

            for ($i = 0; $i < $count; $i++) {
                $hosts[] = long2ip($first + $i);
            }
        }

        return $hosts;
    }

    private static function maskLong(int $prefix): int
    {
        return $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
    }

    /**
     * Massey convention: a host's last octet identifies its role.
     * .10 = access switch, .254 = SD-WAN (EdgeConnect). Anything else is
     * left for the operator to classify at import.
     */
    public static function classifyRole(string $ip): ?string
    {
        $lastOctet = (int) substr($ip, strrpos($ip, '.') + 1);

        return match ($lastOctet) {
            10 => 'switch',
            254 => 'edgeconnect',
            default => null,
        };
    }

    private static function vendorForRole(?string $role): ?string
    {
        return match ($role) {
            'switch' => 'juniper',
            'edgeconnect' => 'silverpeak',
            default => null,
        };
    }

    /** Map SNMP identity to one of the device vendor enum values. */
    public static function guessVendor(?string $sysDescr, ?string $sysObjectId): ?string
    {
        $haystack = strtolower(($sysDescr ?? '').' '.($sysObjectId ?? ''));

        return match (true) {
            str_contains($haystack, '.2636.') || str_contains($haystack, 'juniper') || str_contains($haystack, 'junos') => 'juniper',
            str_contains($haystack, '.23867.') || str_contains($haystack, 'silver peak') || str_contains($haystack, 'silverpeak') || str_contains($haystack, 'edgeconnect') => 'silverpeak',
            str_contains($haystack, '.12356.') || str_contains($haystack, 'fortinet') || str_contains($haystack, 'fortigate') || str_contains($haystack, 'fortios') => 'fortigate',
            default => null,
        };
    }

    /** Best-effort model token from sysDescr; null when nothing recognizable. */
    public static function guessModel(?string $sysDescr, ?string $vendor): ?string
    {
        if (! $sysDescr) {
            return null;
        }

        $pattern = match ($vendor) {
            'juniper' => '/\b((?:ex|qfx|mx|srx|acx)[\w-]+)/i',
            'fortigate' => '/\b(fortigate-?[\w]+)/i',
            'silverpeak' => '/\b(ec-?[\w]+|edgeconnect[\w-]*)/i',
            default => null,
        };

        if ($pattern && preg_match($pattern, $sysDescr, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Auto-match a host's /24 to a Site by its stored subnet, creating a
     * placeholder Site when none exists (operator renames it later).
     */
    public static function siteFor(string $ip): Site
    {
        $long = ip2long($ip) & self::maskLong(24);
        $cidr = long2ip($long).'/24';

        return Site::firstOrCreate(
            ['subnet' => $cidr],
            ['name' => 'Site '.$cidr],
        );
    }
}
