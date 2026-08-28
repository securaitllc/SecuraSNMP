<?php

namespace App\Services;

use App\Models\ArpEntry;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\InterfaceAddress;
use App\Models\IpReservation;
use App\Models\LldpNeighbor;
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
     * Blocks that are locally significant at every site rather than allocated once.
     *
     * 192.168.255.0/24 lives at 127 of the 131 sites and 192.168.1.0/24 at 40. They are
     * not advertised via BGP, so the same numbers are reused independently behind every
     * appliance. Treating them as one range would claim 127 sites "share" it, report an
     * impossible occupancy, and — worst — imply conflicts between hosts that can never
     * see each other. They are not allocatable space and must never be planned from.
     */
    private const SITE_LOCAL_BLOCKS = ['192.168.'];

    /**
     * A range seen at more than this many sites cannot be a single allocation.
     *
     * Co-located service centres genuinely do share one LAN, but only a handful at a
     * time — the most any 10.200 range reaches is 3. Anything far beyond that is the
     * same numbers reused locally, so this catches locally-significant blocks that are
     * not in the declared list above.
     */
    private const SITE_LOCAL_MIN_SITES = 5;

    /**
     * ARP entries whose MAC is all zeroes (or broadcast) are INCOMPLETE resolutions —
     * the gateway asked, nothing answered, and the failure was cached. Reading one as
     * an occupied address is how a genuinely free address gets skipped: 131.148.15.198
     * showed as taken on exactly this.
     */
    private const NULL_MACS = ['00:00:00:00:00:00', 'FF:FF:FF:FF:FF:FF'];

    /**
     * Juniper's internal control-plane interface (bme0) carries 128.0.0.1/2 on every
     * box — 167 of them here, all the same address. It is internal plumbing, not
     * allocatable space, and belongs no more in an IPAM than the loopback does.
     */
    private const INTERNAL_BLOCKS = ['128.0.'];

    /** Enumerating free addresses is bounded; a /24 is 254 rows, a /16 would be 65k. */
    private const MAX_ENUMERATED = 1024;

    /**
     * Which site owns a range is decided by where its DEVICES are addressed, never by
     * how much ARP a gateway happens to hold.
     *
     * A gateway keeps ARP for addresses in other sites' ranges — traffic crossing the
     * SD-WAN fabric leaves traces, and they are not small: 10.200.2.0/24 shows 61
     * addresses at #001 against 56 at #113, yet it belongs to #113, whose appliance and
     * switch are addressed inside it. Counting addresses gets that backwards, and a
     * share threshold called it "shared" by both.
     *
     * Across all 31 contested ranges on this fleet, exactly one site has devices inside
     * each — so a range has one owner. Two sites genuinely addressed inside the same
     * range would be real sharing and both are kept, but that does not occur here.
     */

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
            // Everything known to occupy the block: interface addresses AND the
            // hand-recorded ones. A firewall NAT pool is real consumption that no
            // protocol reports, so leaving it out understates how full a block is.
            $configured = $this->addressesInside($c->subnet);
            $reserved = $this->reservationsInside($c->subnet);
            $occupied = array_unique(array_merge(
                array_map(fn ($a) => $a->ip, $configured),
                array_map(fn ($r) => $r->ip, $reserved),
            ));
            $seen = $pointToPoint ? min(2, $usable) : count($occupied);

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
                'scope' => 'routed',
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
            ->select(['ip', 'site_id', 'mac', 'last_seen_at'])
            ->orderBy('id')
            ->chunk(5000, function (Collection $rows) use (&$byNet, $fresh) {
                foreach ($rows as $r) {
                    if (! self::isPrivate($r->ip) || self::isIncomplete($r->mac)) {
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

        // A device addressed inside a range is the strongest ownership signal there is:
        // the site's own appliance and switch sit in its LAN.
        $deviceOwners = [];
        foreach (Device::whereIn('site_id', $siteIds)->whereNotNull('ip_address')->get(['ip_address', 'site_id']) as $d) {
            $net = self::slashTwentyFour($d->ip_address);
            if ($net !== null) {
                $deviceOwners[$net][$d->site_id] = true;
            }
        }

        $out = [];
        foreach ($byNet as $cidr => $perSite) {
            // Ownership is decided by device addressing, which is authoritative. Only
            // when no device sits inside the range at all does the busiest gateway win,
            // and then exactly one does — never a set.
            if (count($perSite) > 1) {
                $owners = array_keys($deviceOwners[$cidr] ?? []);
                $owners = array_values(array_intersect($owners, array_keys($perSite)));

                if ($owners === []) {
                    $counts = array_map(fn ($d) => count($d['ips'] ?? []), $perSite);
                    arsort($counts);
                    $owners = [array_key_first($counts)];
                }

                $perSite = array_intersect_key($perSite, array_flip($owners));
            }
            if ($perSite === []) {
                continue;
            }

            $siteList = array_keys($perSite);
            $siteLocal = self::isSiteLocalRange($cidr, count($siteList));

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
                    // A site-local range is REPEATED at each site, not shared between
                    // them, so it never carries the other sites as co-owners.
                    'shared_with' => $siteLocal ? [] : array_values(array_diff($siteList, [$sid])),
                    'scope' => $siteLocal ? 'site-local' : 'routed',
                    'state' => $pct >= 85 ? 'critical' : ($pct >= 70 ? 'warning' : 'ok'),
                    'note' => $siteLocal
                        ? 'Site-local — not routed, not allocatable'
                        : (count($siteList) > 1 ? 'Shared with '.(count($siteList) - 1).' other site(s)' : null),
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
            ->get(['ip', 'mac', 'site_id', 'first_seen_at', 'last_seen_at'])
            // An all-zero MAC is a failed resolution, not a host. Counting it occupies
            // an address that is actually free.
            ->reject(fn ($e) => self::isIncomplete($e->mac));

        // The switch FDB already knows the port and VLAN for every one of these MACs.
        // The IP comes from the appliance ARP table and the port from the switch, and
        // joining them is the whole point — reporting "not on a known device" while
        // holding the port in another table is a self-inflicted blind spot.
        $macList = $arp->pluck('mac')->unique()->all();

        $vendors = MacAddress::with(['device:id,name,site_id', 'deviceInterface:id,if_name'])
            ->whereIn('mac', $macList)
            ->get()
            ->keyBy('mac');

        // What the endpoint says about itself over LLDP: hostname, and for a Mitel
        // handset the extension and model it registers with.
        $lldp = LldpNeighbor::whereIn('remote_mac', $macList)
            ->orderByDesc('last_seen_at')
            ->get(['remote_mac', 'remote_sysname', 'extension', 'endpoint_model', 'neighbor_type', 'local_port'])
            ->unique('remote_mac')
            ->keyBy('remote_mac');

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

        // Hand-recorded addresses: the firewall NAT pools and VIPs that appear in no
        // SNMP table. Recorded once, they must never be offered as free again.
        $reservations = IpReservation::with(['device:id,name', 'site:id,name'])
            ->where('ip', 'like', $prefixStr.'%')
            ->get()
            ->keyBy('ip');

        // One IP claimed by two MACs at the same site is a genuine conflict.
        $byIp = $arp->groupBy('ip');

        // MAC => the device that owns it, where one MAC covers several addresses.
        $macOwners = [];
        foreach ($arp->groupBy('mac') as $mac => $entries) {
            $ips = $entries->pluck('ip')->unique();
            if ($ips->count() > 1) {
                $owner = $configured->firstWhere(fn ($c) => $ips->contains($c->ip));
                $macOwners[$mac] = $owner?->device?->name;
            }
        }
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

            // One MAC answering for several addresses in the range is a single device
            // with secondaries or proxy-ARP — a FortiGate answers for .194, .200 and
            // .213 here. Attribute them to it rather than listing unknown hosts.
            $owner = $macOwners[$macs->first()] ?? null;
            $fdb = $vendors->get($macs->first());
            $seenBy = $lldp->get($macs->first());

            $rows[] = [
                'ip' => $ip,
                'sort' => self::ipSort($ip),
                'state' => $state,
                'also_on' => $owner,
                'mac' => $macs->count() > 1 ? $macs->implode(', ') : $macs->first(),
                'vendor' => $vendor?->oui_vendor,
                // Where the endpoint physically plugs in.
                'switch' => $fdb?->device?->name,
                'switch_port' => $fdb?->deviceInterface?->if_name,
                'vlan' => $fdb?->vlan ?: null,
                // What it says it is.
                'hostname' => $seenBy?->remote_sysname,
                'extension' => $seenBy?->extension,
                'endpoint_model' => $seenBy?->endpoint_model,
                'endpoint_kind' => self::endpointKind($vendor?->oui_vendor, $seenBy?->neighbor_type, $seenBy?->endpoint_model),
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
                // Only an ARP-discovered address can go stale. One backed by a device
                // record or a configured interface is known from configuration, and a
                // device never ARPs its own address.
                'stale' => ($device || $iface)
                    ? false
                    : ($latest?->last_seen_at ? $latest->last_seen_at->lessThan($fresh) : true),
            ];
        }

        // A device with an address nothing has ARPed for still belongs in the map.
        foreach ($devices as $ip => $d) {
            if (! $byIp->has($ip)) {
                $rows[] = [
                    'ip' => $ip, 'sort' => self::ipSort($ip), 'state' => 'assigned',
                    'mac' => null, 'vendor' => null, 'also_on' => null,
                    'device_id' => $d->id, 'device_name' => $d->name, 'device_role' => $d->role,
                    'interface' => $configured->get($ip)?->interface?->if_name,
                    'switch' => null, 'switch_port' => null, 'vlan' => null,
                    'hostname' => null, 'extension' => null,
                    'endpoint_model' => null, 'endpoint_kind' => null,
                    'prefix_len' => $configured->get($ip)?->prefix_len,
                    'is_public' => $configured->get($ip)?->is_public ?? false,
                    'first_seen_at' => null,
                    'last_seen_at' => $configured->get($ip)?->last_seen_at,
                    // A device does not ARP itself, so its own management address has no
                    // sighting — that is not staleness. Only an ARP-discovered address
                    // can go stale; a configured one is known from the device's own config.
                    'stale' => false,
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
                'mac' => null, 'vendor' => null, 'also_on' => null,
                'device_id' => $a->device_id, 'device_name' => $a->device?->name,
                'device_role' => $a->device?->role,
                'interface' => $a->interface?->if_name,
                'switch' => null, 'switch_port' => null, 'vlan' => null,
                'hostname' => null, 'extension' => null,
                'endpoint_model' => null, 'endpoint_kind' => null,
                'prefix_len' => $a->prefix_len,
                'is_public' => $a->is_public,
                'first_seen_at' => $a->first_seen_at, 'last_seen_at' => $a->last_seen_at,
                'stale' => false,
            ];
        }

        foreach ($reservations as $ip => $r) {
            if ($byIp->has($ip) || $devices->has($ip) || $configured->has($ip)) {
                // Already known from the wire; note the recorded purpose on that row
                // rather than adding a second line for the same address.
                foreach ($rows as &$row) {
                    if ($row['ip'] === $ip) {
                        $row['reservation'] = ['label' => $r->label, 'purpose' => $r->purpose, 'note' => $r->note];
                    }
                }
                unset($row);
                continue;
            }
            $rows[] = [
                'ip' => $ip, 'sort' => self::ipSort($ip), 'state' => 'reserved',
                'mac' => null, 'vendor' => null, 'also_on' => null,
                'switch' => null, 'switch_port' => null, 'vlan' => null,
                'hostname' => $r->label, 'extension' => null,
                'endpoint_model' => null, 'endpoint_kind' => null,
                'device_id' => $r->device_id, 'device_name' => $r->device?->name,
                'device_role' => null,
                'interface' => null, 'prefix_len' => $r->prefix_len,
                'is_public' => self::isPublicIp($ip),
                'reservation' => ['label' => $r->label, 'purpose' => $r->purpose, 'note' => $r->note],
                'first_seen_at' => null, 'last_seen_at' => null, 'stale' => false,
            ];
        }

        // Every address in the block that nothing occupies, listed explicitly. Without
        // this the map showed only what was taken and left "what can I actually use?"
        // to be worked out by hand from the gaps.
        $taken = array_column($rows, 'ip');
        $usable = self::usableAddresses($net['prefix']);
        $size = 2 ** (32 - $net['prefix']);

        if ($size <= self::MAX_ENUMERATED) {
            $base = ip2long($net['base']) & ($net['prefix'] === 0 ? 0 : -1 << (32 - $net['prefix']));
            $first = $net['prefix'] >= 31 ? $base : $base + 1;          // skip the network address
            $last = $net['prefix'] >= 31 ? $base + $size - 1 : $base + $size - 2;  // and the broadcast

            for ($l = $first; $l <= $last; $l++) {
                $ip = long2ip($l);
                if (in_array($ip, $taken, true)) {
                    continue;
                }
                $rows[] = [
                    'ip' => $ip, 'sort' => $l, 'state' => 'free',
                    'mac' => null, 'vendor' => null, 'also_on' => null,
                    'switch' => null, 'switch_port' => null, 'vlan' => null,
                    'hostname' => null, 'extension' => null,
                    'endpoint_model' => null, 'endpoint_kind' => null,
                    'device_id' => null, 'device_name' => null, 'device_role' => null,
                    'interface' => null, 'prefix_len' => $net['prefix'], 'is_public' => self::isPublicIp($ip),
                    'first_seen_at' => null, 'last_seen_at' => null, 'stale' => false,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['state'] === $s));
        $reservedCount = count(array_filter($rows, fn ($r) => ($r['reservation'] ?? null) !== null));

        return [
            'cidr' => $cidr,
            'rows' => $rows,
            'summary' => [
                'usable' => $usable,
                'assigned' => $count('assigned'),
                'discovered' => $count('discovered'),
                'conflict' => $count('conflict'),
                'reserved' => $reservedCount,
                'free' => $size <= self::MAX_ENUMERATED
                    ? $count('free')
                    : max(0, $usable - count($rows)),
                'enumerated' => $size <= self::MAX_ENUMERATED,
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
     * Hand-recorded addresses inside a CIDR block.
     *
     * @return array<int, \App\Models\IpReservation>
     */
    private function reservationsInside(string $cidr): array
    {
        $this->reservationCache ??= IpReservation::get(['ip'])->all();

        return array_values(array_filter($this->reservationCache, fn ($r) => self::inside($r->ip, $cidr)));
    }

    /** @var array<int, \App\Models\IpReservation>|null */
    private ?array $reservationCache = null;

    /** Does this address fall inside the block? */
    public static function inside(string $ip, string $cidr): bool
    {
        $net = self::parseCidr($cidr);
        $l = ip2long($ip);
        if ($net === null || $l === false) {
            return false;
        }
        $base = ip2long($net['base']);
        if ($base === false) {
            return false;
        }
        $mask = $net['prefix'] === 0 ? 0 : -1 << (32 - $net['prefix']);
        $start = $base & $mask;

        return $l >= $start && $l <= $start + (2 ** (32 - $net['prefix'])) - 1;
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
        $all = collect($sites)->flatMap(fn ($s) => $s['ranges']);
        // Only routed ranges are allocations; site-local blocks are counted apart so
        // setting them aside is visible rather than a silent omission.
        $ranges = $all->where('scope', 'routed');
        $lan = $ranges->where('kind', 'lan');

        return [
            'sites' => count($sites),
            'ranges' => $ranges->unique('cidr')->count(),
            'wan' => $ranges->where('kind', 'wan')->unique('cidr')->count(),
            'lan' => $lan->unique('cidr')->count(),
            'site_local' => $all->where('scope', 'site-local')->unique('cidr')->count(),
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

    /**
     * What kind of endpoint this is, from the strongest signal available.
     *
     * LLDP first — a device that announces itself as a phone is a phone. The OUI is a
     * fallback: it identifies the manufacturer, and for these vendors that is a
     * reliable proxy for the class of kit. Anything unrecognised stays null rather
     * than being guessed at.
     */
    public static function endpointKind(?string $vendor, ?string $neighborType, ?string $model): ?string
    {
        $t = strtolower((string) $neighborType);
        if (str_contains($t, 'phone')) {
            return 'phone';
        }
        if (str_contains($t, 'wlan') || str_contains($t, 'ap')) {
            return 'access-point';
        }

        $v = strtolower((string) $vendor);
        if ($v === '') {
            return null;
        }
        if (str_contains($v, 'mitel') || str_contains($v, 'polycom') || str_contains($v, 'yealink')) {
            return 'phone';
        }
        if (str_contains($v, 'ubiquiti') || str_contains($v, 'aruba') || str_contains($v, 'ruckus') || str_contains($v, 'meraki')) {
            return 'access-point';
        }
        if (str_contains($v, 'axis') || str_contains($v, 'verkada') || str_contains($v, 'hanwha')) {
            return 'camera';
        }
        if (str_contains($v, 'askey') || str_contains($v, 'arris') || str_contains($v, 'technicolor')) {
            return 'modem';
        }

        return null;
    }

    /** An ARP entry with an all-zero or broadcast MAC is a failed resolution. */
    public static function isIncomplete(?string $mac): bool
    {
        return $mac === null || in_array(strtoupper(trim($mac)), self::NULL_MACS, true);
    }

    /** Routable on the internet — used to label a free address honestly. */
    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Is this range locally significant at each site rather than allocated once?
     *
     * Declared blocks first, then the empirical guard for anything not on that list.
     */
    public static function isSiteLocalRange(string $cidr, int $siteCount): bool
    {
        foreach (self::SITE_LOCAL_BLOCKS as $block) {
            if (str_starts_with($cidr, $block)) {
                return true;
            }
        }

        return $siteCount > self::SITE_LOCAL_MIN_SITES;
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
