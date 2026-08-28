<?php

namespace App\Services;

use App\Models\ArpEntry;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\InterfaceAddress;
use App\Models\MacAddress;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * IP address management, derived from what the network actually reports.
 *
 * Two kinds of range live here and they come from different places:
 *
 *  - WAN ranges are RECORDED. Each circuit carries the subnet and gateway the ISP
 *    allocated, so those are read straight off the circuit.
 *  - LAN ranges are OBSERVED. Almost none are written down, but every appliance ARP
 *    table says exactly which addresses are live behind it, so the range is inferred
 *    from the addresses actually seen at each site.
 *
 * The observed half is deliberate. The obvious shortcut — assume a site's LAN is
 * 10.200.<site number>.0/24 — is wrong on this fleet: 10.200.77.0/24 serves site #106,
 * and co-located service centres SHARE one LAN (10.200.56.0/24 carries #041, #056 and
 * #209 together). Computing the range from the site number would invent addressing
 * that does not exist and miss the sharing entirely, so nothing here does that.
 *
 * Aggregation happens in PHP rather than SQL on purpose: splitting an address into
 * octets needs SUBSTRING_INDEX on MySQL and a different expression on SQLite, and
 * that divergence has produced production-only failures in this codebase before.
 * The row counts are small enough (~13k distinct addresses) that portability wins.
 */
class Ipam
{
    /** An address unseen for this long is stale — reported, but never counted as live. */
    public const FRESH_HOURS = 24;

    /** The corporate supernet the planner allocates new site LANs from. */
    public const SUPERNET = '10.200.0.0/16';

    /**
     * Circuits whose recorded subnet is not a CIDR — a bare netmask, say. They cannot
     * be placed on the map, and dropping them quietly would understate WAN coverage
     * with nothing on screen to explain the gap, so they are counted and reported.
     */
    private int $unreadableWan = 0;

    /**
     * Every range the fleet uses, grouped by the site that owns it.
     *
     * @return array{sites: array, summary: array}
     */
    public function ranges(?int $siteId = null): array
    {
        $sites = Site::query()
            ->when($siteId, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $wan = $this->wanRanges($sites->keys()->all());
        $lan = $this->lanRanges($sites->keys()->all());

        $out = [];
        foreach ($sites as $site) {
            $ranges = array_merge($wan[$site->id] ?? [], $lan[$site->id] ?? []);
            if ($ranges === []) {
                continue;
            }

            // Worst state on any of its ranges decides how the site reads.
            $worst = 'ok';
            foreach ($ranges as $r) {
                if ($r['state'] === 'critical') {
                    $worst = 'critical';
                } elseif ($r['state'] === 'warning' && $worst !== 'critical') {
                    $worst = 'warning';
                }
            }

            $out[] = [
                'site_id' => $site->id,
                'site_number' => $site->site_number,
                'site_name' => $site->name,
                'address' => $site->address,
                'state' => $worst,
                'ranges' => $ranges,
            ];
        }

        return ['sites' => $out, 'summary' => $this->summary($out)];
    }

    /**
     * WAN ranges, read off the circuits. A /30 sitting at 2 of 2 addresses is not
     * "full" in any meaningful sense — that is simply what a point-to-point link is —
     * so those are never flagged, however high the percentage reads.
     *
     * @return array<int, array<int, array>>
     */
    private function wanRanges(array $siteIds): array
    {
        $out = [];
        $circuits = Circuit::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('subnet')
            ->where('subnet', '!=', '')
            ->with('ispProvider')
            ->get();

        foreach ($circuits as $c) {
            $net = self::parseCidr($c->subnet);
            if ($net === null) {
                $this->unreadableWan++;
                continue;
            }
            $usable = self::usableAddresses($net['prefix']);
            $pointToPoint = $net['prefix'] >= 30;

            // Real occupancy where we have it: the addresses actually configured on
            // device interfaces inside this block. A /30 has nothing to count, but a
            // /27 handed to HQ does — and that is the block someone is about to
            // allocate from.
            $configured = $this->addressesInside($c->subnet);
            $seen = $pointToPoint ? min(2, $usable) : max(count($configured), 0);

            $out[$c->site_id][] = [
                'cidr' => $c->subnet,
                'kind' => 'wan',
                'label' => $c->circuit_id,
                'gateway' => $c->gateway_ip,
                'isp' => $c->isp_name,
                'lec' => $c->lec_name,
                'circuit_id' => $c->id,
                'circuit_type' => $c->circuit_type,
                'usable' => $usable,
                'seen' => $seen,
                'pct' => $usable > 0 ? (int) round(min($seen, $usable) / $usable * 100) : 0,
                'shared_with' => [],
                'state' => $pointToPoint ? 'ok' : ($usable > 0 && $seen / $usable >= 0.85 ? 'critical' : ($usable > 0 && $seen / $usable >= 0.7 ? 'warning' : 'ok')),
                'note' => $pointToPoint ? 'Point-to-point link' : null,
                'recorded' => true,
            ];
        }

        return $out;
    }

    /**
     * LAN ranges, inferred from the addresses each site's appliances actually ARP for.
     *
     * A /24 that turns up at more than one site is ONE range shared between them, not
     * a coincidence — co-located service centres sit behind the same appliance. It is
     * reported against every site it serves, each carrying the others in shared_with.
     *
     * @return array<int, array<int, array>>
     */
    private function lanRanges(array $siteIds): array
    {
        $fresh = now()->subHours(self::FRESH_HOURS);

        // /24 => site_id => set of addresses
        $byNet = [];
        ArpEntry::query()
            ->whereIn('site_id', $siteIds)
            ->select(['ip', 'site_id', 'last_seen_at'])
            ->orderBy('id')
            ->chunk(5000, function (Collection $rows) use (&$byNet, $fresh) {
                foreach ($rows as $r) {
                    if (! self::isPrivate($r->ip)) {
                        continue;   // WAN-side neighbours belong to the circuit, not a LAN
                    }
                    $net = self::slashTwentyFour($r->ip);
                    if ($net === null) {
                        continue;
                    }
                    $byNet[$net][$r->site_id]['ips'][$r->ip] = true;
                    if ($r->last_seen_at && $r->last_seen_at->greaterThanOrEqualTo($fresh)) {
                        $byNet[$net][$r->site_id]['fresh'][$r->ip] = true;
                    }
                }
            });

        // A device's own management address counts as occupancy even if nothing ARPed it.
        foreach (Device::whereIn('site_id', $siteIds)->whereNotNull('ip_address')->get(['ip_address', 'site_id']) as $d) {
            if (! self::isPrivate($d->ip_address)) {
                continue;
            }
            $net = self::slashTwentyFour($d->ip_address);
            if ($net !== null) {
                $byNet[$net][$d->site_id]['ips'][$d->ip_address] = true;
            }
        }

        $recorded = Site::whereIn('id', $siteIds)->whereNotNull('subnet')->where('subnet', '!=', '')
            ->pluck('subnet', 'id')->all();

        $out = [];
        foreach ($byNet as $cidr => $perSite) {
            $siteList = array_keys($perSite);
            foreach ($perSite as $sid => $data) {
                $seen = count($data['ips'] ?? []);
                $fresh_n = count($data['fresh'] ?? []);
                $pct = (int) round($seen / 254 * 100);

                $out[$sid][] = [
                    'cidr' => $cidr,
                    'kind' => 'lan',
                    'label' => null,
                    'gateway' => null,
                    'isp' => null,
                    'lec' => null,
                    'circuit_id' => null,
                    'circuit_type' => null,
                    'usable' => 254,
                    'seen' => $seen,
                    'fresh' => $fresh_n,
                    'pct' => $pct,
                    'shared_with' => array_values(array_diff($siteList, [$sid])),
                    'state' => $pct >= 85 ? 'critical' : ($pct >= 70 ? 'warning' : 'ok'),
                    'note' => count($siteList) > 1 ? 'Shared with '.(count($siteList) - 1).' other site(s)' : null,
                    // Whether anyone wrote this range down — the gap the page exists to close.
                    'recorded' => ($recorded[$sid] ?? null) === $cidr,
                ];
            }
        }

        return $out;
    }

    /**
     * Every address inside one range, and what is known about each.
     *
     * @return array{cidr: string, rows: array, summary: array}
     */
    public function detail(string $cidr, ?int $siteId = null): array
    {
        $net = self::parseCidr($cidr);
        if ($net === null) {
            return ['cidr' => $cidr, 'rows' => [], 'summary' => []];
        }

        $prefixStr = implode('.', array_slice(explode('.', $net['base']), 0, 3)).'.';

        $arp = ArpEntry::query()
            ->where('ip', 'like', $prefixStr.'%')
            ->when($siteId, fn ($q, $id) => $q->where('site_id', $id))
            ->get(['ip', 'mac', 'site_id', 'first_seen_at', 'last_seen_at']);

        $vendors = MacAddress::whereIn('mac', $arp->pluck('mac')->unique()->all())
            ->get(['mac', 'oui_vendor', 'device_interface_id'])
            ->keyBy('mac');

        $devices = Device::whereNotNull('ip_address')
            ->where('ip_address', 'like', $prefixStr.'%')
            ->get(['id', 'name', 'ip_address', 'role', 'site_id'])
            ->keyBy('ip_address');

        // Addresses configured on a device's own interfaces. These are the ones an
        // operator most needs before allocating: an HA pair's WAN addresses answer no
        // ARP of their own and are not any device's single management address, so
        // without this they looked free.
        $configured = InterfaceAddress::with(['device:id,name,role', 'interface:id,if_name'])
            ->where('ip', 'like', $prefixStr.'%')
            ->get()
            ->keyBy('ip');

        // One IP claimed by two MACs at the same site is a genuine conflict.
        $byIp = $arp->groupBy('ip');
        $fresh = now()->subHours(self::FRESH_HOURS);

        $rows = [];
        foreach ($byIp as $ip => $entries) {
            $macs = $entries->pluck('mac')->unique()->values();
            $device = $devices->get($ip);
            $latest = $entries->sortByDesc('last_seen_at')->first();
            $vendor = $vendors->get($macs->first());

            $iface = $configured->get($ip);

            $state = 'discovered';
            if ($macs->count() > 1) {
                $state = 'conflict';
            } elseif ($device || $iface) {
                $state = 'assigned';
            }

            $rows[] = [
                'ip' => $ip,
                'sort' => self::ipSort($ip),
                'state' => $state,
                'mac' => $macs->count() > 1 ? $macs->implode(', ') : $macs->first(),
                'vendor' => $vendor?->oui_vendor,
                'device_id' => $device?->id ?? $iface?->device_id,
                'device_name' => $device?->name ?? $iface?->device?->name,
                'device_role' => $device?->role ?? $iface?->device?->role,
                'interface' => $iface?->interface?->if_name,
                'prefix_len' => $iface?->prefix_len,
                'is_public' => $iface?->is_public ?? false,
                'first_seen_at' => $latest?->first_seen_at,
                'last_seen_at' => $latest?->last_seen_at,
                // Silence is not health: an address nothing has answered for in a day
                // is reported as stale rather than quietly counted as live.
                'stale' => $latest?->last_seen_at ? $latest->last_seen_at->lessThan($fresh) : true,
            ];
        }

        // A device with an address nothing has ARPed for still belongs in the map.
        foreach ($devices as $ip => $d) {
            if (! $byIp->has($ip)) {
                $rows[] = [
                    'ip' => $ip, 'sort' => self::ipSort($ip), 'state' => 'assigned',
                    'mac' => null, 'vendor' => null,
                    'device_id' => $d->id, 'device_name' => $d->name, 'device_role' => $d->role,
                    'interface' => $configured->get($ip)?->interface?->if_name,
                    'prefix_len' => $configured->get($ip)?->prefix_len,
                    'is_public' => $configured->get($ip)?->is_public ?? false,
                    'first_seen_at' => null, 'last_seen_at' => null, 'stale' => true,
                ];
            }
        }

        // The case this feature was built for: a WAN address on an HA appliance answers
        // no ARP and is not the device's management address, so it would otherwise be
        // absent from the map — and read as free to the next person allocating.
        foreach ($configured as $ip => $a) {
            if ($byIp->has($ip) || $devices->has($ip)) {
                continue;
            }
            $rows[] = [
                'ip' => $ip, 'sort' => self::ipSort($ip), 'state' => 'assigned',
                'mac' => null, 'vendor' => null,
                'device_id' => $a->device_id, 'device_name' => $a->device?->name,
                'device_role' => $a->device?->role,
                'interface' => $a->interface?->if_name,
                'prefix_len' => $a->prefix_len,
                'is_public' => $a->is_public,
                'first_seen_at' => $a->first_seen_at, 'last_seen_at' => $a->last_seen_at,
                'stale' => false,
            ];
        }

        usort($rows, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['state'] === $s));
        $usable = self::usableAddresses($net['prefix']);

        return [
            'cidr' => $cidr,
            'rows' => $rows,
            'summary' => [
                'usable' => $usable,
                'assigned' => $count('assigned'),
                'discovered' => $count('discovered'),
                'conflict' => $count('conflict'),
                'free' => max(0, $usable - count($rows)),
                'stale' => count(array_filter($rows, fn ($r) => $r['stale'])),
            ],
        ];
    }

    /**
     * Which /24s of the corporate supernet are free for a new site.
     *
     * Occupancy comes from the wire — anything ARP has seen, plus every device
     * management address — so a range nobody documented still reads as taken. That is
     * the point: the documented list has 3 entries and the network has 128.
     *
     * @return array{supernet: string, blocks: array, runs: array, summary: array}
     */
    public function space(string $supernet = self::SUPERNET): array
    {
        $net = self::parseCidr($supernet);
        if ($net === null || $net['prefix'] !== 16) {
            return ['supernet' => $supernet, 'blocks' => [], 'runs' => [], 'summary' => []];
        }
        [$a, $b] = array_slice(explode('.', $net['base']), 0, 2);
        $prefix = "{$a}.{$b}.";

        $seen = [];   // octet => distinct addresses
        ArpEntry::query()->where('ip', 'like', $prefix.'%')->select(['ip'])
            ->orderBy('id')->chunk(5000, function (Collection $rows) use (&$seen, $prefix) {
                foreach ($rows as $r) {
                    $o = self::thirdOctet($r->ip, $prefix);
                    if ($o !== null) {
                        $seen[$o][$r->ip] = true;
                    }
                }
            });

        foreach (Device::whereNotNull('ip_address')->where('ip_address', 'like', $prefix.'%')->pluck('ip_address') as $ip) {
            $o = self::thirdOctet($ip, $prefix);
            if ($o !== null) {
                $seen[$o][$ip] = true;
            }
        }

        $blocks = [];
        for ($i = 0; $i <= 255; $i++) {
            $n = isset($seen[$i]) ? count($seen[$i]) : 0;
            // .0 and .255 are left alone by convention rather than allocated.
            $reserved = $i === 0 || $i === 255;
            $blocks[] = [
                'octet' => $i,
                'cidr' => "{$prefix}{$i}.0/24",
                'seen' => $n,
                'pct' => (int) round($n / 254 * 100),
                'state' => $reserved ? 'reserved' : ($n > 0 ? ($n / 254 >= 0.7 ? 'busy' : 'used') : 'free'),
            ];
        }

        // Contiguous free runs, longest first — a new site wants room to grow into.
        $runs = [];
        $start = null;
        foreach ($blocks as $blk) {
            if ($blk['state'] === 'free') {
                $start ??= $blk['octet'];
                $last = $blk['octet'];
            } elseif ($start !== null) {
                $runs[] = ['from' => $start, 'to' => $last, 'size' => $last - $start + 1];
                $start = null;
            }
        }
        if ($start !== null) {
            $runs[] = ['from' => $start, 'to' => $last, 'size' => $last - $start + 1];
        }
        usort($runs, fn ($x, $y) => $y['size'] <=> $x['size']);

        $free = count(array_filter($blocks, fn ($b) => $b['state'] === 'free'));

        return [
            'supernet' => $supernet,
            'blocks' => $blocks,
            'runs' => array_slice($runs, 0, 10),
            'summary' => [
                'total' => 256,
                'in_use' => count(array_filter($blocks, fn ($b) => in_array($b['state'], ['used', 'busy'], true))),
                'free' => $free,
                'reserved' => count(array_filter($blocks, fn ($b) => $b['state'] === 'reserved')),
                'largest_run' => $runs[0]['size'] ?? 0,
                'suggested' => isset($runs[0]) ? "{$prefix}{$runs[0]['from']}.0/24" : null,
            ],
        ];
    }

    /**
     * Configured interface addresses that fall inside a CIDR block.
     *
     * @return array<int, \App\Models\InterfaceAddress>
     */
    private function addressesInside(string $cidr): array
    {
        $net = self::parseCidr($cidr);
        if ($net === null) {
            return [];
        }
        $base = ip2long($net['base']);
        if ($base === false) {
            return [];
        }
        $mask = $net['prefix'] === 0 ? 0 : -1 << (32 - $net['prefix']);
        $start = $base & $mask;
        $end = $start + (2 ** (32 - $net['prefix'])) - 1;

        $this->configuredCache ??= InterfaceAddress::get(['ip', 'device_id'])->all();

        return array_values(array_filter($this->configuredCache, function ($a) use ($start, $end) {
            $l = ip2long($a->ip);

            return $l !== false && $l >= $start && $l <= $end;
        }));
    }

    /** @var array<int, \App\Models\InterfaceAddress>|null */
    private ?array $configuredCache = null;

    /** @param array<int, array> $sites */
    private function summary(array $sites): array
    {
        $ranges = collect($sites)->flatMap(fn ($s) => $s['ranges']);
        $lan = $ranges->where('kind', 'lan');

        return [
            'sites' => count($sites),
            'ranges' => $ranges->unique('cidr')->count(),
            'wan' => $ranges->where('kind', 'wan')->unique('cidr')->count(),
            'lan' => $lan->unique('cidr')->count(),
            'addresses_seen' => (int) $lan->unique('cidr')->sum('seen'),
            'unrecorded_lan' => $lan->unique('cidr')->where('recorded', false)->count(),
            'needs_attention' => $ranges->whereIn('state', ['warning', 'critical'])->unique('cidr')->count(),
            // Visible rather than silently missing — see $unreadableWan.
            'unreadable_wan' => $this->unreadableWan,
            'public_configured' => InterfaceAddress::where('is_public', true)->count(),
        ];
    }

    // ---- address helpers -------------------------------------------------------

    /** @return array{base: string, prefix: int}|null */
    public static function parseCidr(?string $cidr): ?array
    {
        if (! $cidr || ! str_contains($cidr, '/')) {
            return null;
        }
        [$base, $prefix] = explode('/', trim($cidr), 2);
        if (! filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! is_numeric($prefix)) {
            return null;
        }
        $prefix = (int) $prefix;

        return ($prefix < 0 || $prefix > 32) ? null : ['base' => $base, 'prefix' => $prefix];
    }

    /** Hosts in a prefix. A /31 and /32 have no network+broadcast pair to deduct. */
    public static function usableAddresses(int $prefix): int
    {
        if ($prefix >= 31) {
            return $prefix === 32 ? 1 : 2;
        }

        return (2 ** (32 - $prefix)) - 2;
    }

    public static function slashTwentyFour(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $p = explode('.', $ip);

        return "{$p[0]}.{$p[1]}.{$p[2]}.0/24";
    }

    /** RFC1918 only. Link-local (169.254) is deliberately NOT a LAN range. */
    public static function isPrivate(?string $ip): bool
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)
            && ! str_starts_with($ip, '169.254.');
    }

    private static function thirdOctet(string $ip, string $prefix): ?int
    {
        if (! str_starts_with($ip, $prefix)) {
            return null;
        }
        $p = explode('.', $ip);

        return isset($p[2]) && is_numeric($p[2]) ? (int) $p[2] : null;
    }

    public static function ipSort(string $ip): int
    {
        $l = ip2long($ip);

        return $l === false ? 0 : $l;
    }
}
