<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Support\MacOui;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Learns the switch MAC forwarding database over SNMP (standard Q-BRIDGE MIB, so
 * any vendor that answers) and upserts one row per (device, mac, vlan) with
 * OUI-resolved vendor + last_seen. A port that later goes down keeps its last
 * learned MACs here until the retention prune drops them — that's how a down port
 * still shows "what was connected".
 *
 * Injectable walker (callable(Device,string):string returning raw snmpwalk stdout)
 * so the parse is unit-testable with a fake walk.
 */
class MacPoller
{
    // dot1qTpFdbPort: index = <vlan>.<mac 6 octets>, value = bridge port number.
    private const OID_FDB_PORT = '.1.3.6.1.2.1.17.7.1.2.2.1.2';
    // dot1dBasePortIfIndex: index = <bridge port>, value = ifIndex.
    private const OID_PORT_IFIDX = '.1.3.6.1.2.1.17.1.4.1.2';
    // Two Juniper VLAN lookups, both keyed by the internal VLAN index:
    //   older EX  → jnxExVlanTag  (index → 802.1Q tag number), fdbId = index
    //   ELS/Mist  → jnxL2ald name (index → VLAN name),         fdbId = index << 16
    private const OID_VLAN_TAG = '.1.3.6.1.4.1.2636.3.40.1.5.1.5.1.5';
    private const OID_VLAN_NAME = '.1.3.6.1.4.1.2636.3.48.1.3.1.1.2';

    /** @param  callable(Device, string): string  $walker */
    public function __construct(private $walker) {}

    /** Poll one device; returns the number of MAC rows upserted. */
    public function poll(Device $device, ?Carbon $now = null): int
    {
        $now = $now ?? now();

        $fdb = self::parseFdb(($this->walker)($device, self::OID_FDB_PORT));
        if ($fdb === []) {
            return 0; // no bridge table (router/appliance) — nothing to learn
        }

        // Batch-sanity guard: a corrupt/truncated SNMP FDB read parses into a flood of
        // impossible MACs (e.g. thousands of 00:00:xx). Those then persist for the whole
        // 90-day retention because upsert keys on the (device, mac) pair, so a later GOOD
        // poll — which produces DIFFERENT (correct) macs — can never overwrite them. Reject
        // an implausible batch outright, keeping the last-good rows, rather than poison it.
        if (! self::plausibleBatch($fdb)) {
            Log::warning("MAC poll for {$device->name} rejected — corrupt FDB read (implausible MAC distribution)", [
                'device_id' => $device->id,
                'count' => count($fdb),
            ]);

            return 0;
        }

        $portToIf = self::parsePortIfIndex(($this->walker)($device, self::OID_PORT_IFIDX));
        // Juniper VLAN lookups (both empty on non-Juniper gear, where fdbId IS the VLAN).
        $vlanTags = self::parseVlanTags(($this->walker)($device, self::OID_VLAN_TAG));
        $vlanNames = self::parseVlanNames(($this->walker)($device, self::OID_VLAN_NAME));
        $ifByIndex = DeviceInterface::where('device_id', $device->id)->pluck('id', 'if_index');

        $count = 0;
        foreach ($fdb as $e) {
            $mac = MacOui::normalize($e['mac']);
            if ($mac === null) {
                continue;
            }
            // dot1qTpFdbPort is a bridge port on most gear (map via dot1dBasePortIfIndex),
            // but Juniper returns the ifIndex directly — so fall back to the raw value.
            $ifIndex = $portToIf[$e['port']] ?? $e['port'];
            // ELS/Mist encode the VLAN index in the high bits (index<<16); older EX
            // uses it plain. Resolve a label: name (best), else tag, else the index.
            $idx = $e['vlan'] >= 65536 ? ($e['vlan'] >> 16) : $e['vlan'];
            $vlan = $vlanNames[$idx] ?? (isset($vlanTags[$idx]) ? (string) $vlanTags[$idx] : (string) $idx);

            // Identity is (device, mac) — NOT (device, mac, vlan). Keying on the VLAN
            // label meant that whenever a MAC's decoded label changed over time (a raw
            // index like "262144" → wrong name → correct name, or a poll where the
            // jnxL2ald name walk dropped and we fell back to the index), a NEW row was
            // born and the old one lingered until the 90-day prune — so the device page
            // showed the same MAC two or three times. One row per (device, mac); update
            // its VLAN/port in place, and collapse any historical forks each time it is
            // re-seen so the table self-heals within a poll cycle.
            $row = MacAddress::firstOrNew(['device_id' => $device->id, 'mac' => $mac]);
            if (! $row->exists) {
                $row->first_seen_at = $now;
            }
            // Remove any other rows for this MAC first, so updating this row's VLAN can
            // never collide with a stale fork on the (device, mac, vlan) unique index.
            MacAddress::where('device_id', $device->id)
                ->where('mac', $mac)
                ->when($row->exists, fn ($q) => $q->where('id', '!=', $row->id))
                ->delete();
            $row->vlan = $vlan;
            $row->device_interface_id = $ifByIndex[$ifIndex] ?? null;
            $row->oui_vendor = MacOui::vendor($mac);
            $row->last_seen_at = $now;
            $row->save();
            $count++;
        }

        return $count;
    }

    /**
     * Parse dot1qTpFdbPort. The OID index is <vlan>.<6 mac octets> (7 numeric
     * components) and the value is the bridge port. Robust to numeric or MIB-named
     * OID prefixes — we take the last 7 dot-components of the index.
     *
     * @return list<array{vlan:int,mac:string,port:int}>
     */
    /**
     * Is a parsed FDB batch plausibly a real switch's MAC table, or a corrupt SNMP read?
     *
     * A real table is OUI-diverse (phones, PCs, APs, printers). A corrupt/shifted walk
     * floods near-identical or reserved MACs. Two signals, both near-zero on a healthy poll:
     *   - non-VRRP 00:00:* MACs (an OUI no modern endpoint uses; 00:00:5E is legit VRRP)
     *   - one 5-octet prefix dominating (real gear never shares 5 octets en masse — the
     *     last serial byte always varies per device)
     * Small batches are never blocked — too little signal, and a tiny switch is normal.
     *
     * @param  list<array{vlan:int,mac:string,port:int}>  $fdb
     */
    public static function plausibleBatch(array $fdb): bool
    {
        $n = count($fdb);
        if ($n < 20) {
            return true;
        }

        $reserved = 0;
        $prefix5 = [];
        foreach ($fdb as $e) {
            $mac = strtoupper((string) $e['mac']);
            if (str_starts_with($mac, '00:00:') && ! str_starts_with($mac, '00:00:5E')) {
                $reserved++;
            }
            $p = substr($mac, 0, 14); // first 5 octets "AA:BB:CC:DD:EE"
            $prefix5[$p] = ($prefix5[$p] ?? 0) + 1;
        }

        return $reserved / $n <= 0.30 && max($prefix5) / $n <= 0.40;
    }

    public static function parseFdb(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            if (! preg_match('/^(\S+?)\s*=\s*(?:\w+:\s*)?(\d+)\s*$/', trim($line), $m)) {
                continue;
            }
            $port = (int) $m[2];
            if ($port <= 0) {
                continue; // 0 = not learned on a port
            }
            $parts = array_values(array_filter(explode('.', $m[1]), fn ($p) => $p !== ''));
            if (count($parts) < 7) {
                continue;
            }
            $idx = array_slice($parts, -7);
            if (count(array_filter($idx, 'ctype_digit')) !== 7) {
                continue;
            }
            $mac = strtoupper(implode(':', array_map(fn ($o) => sprintf('%02x', (int) $o), array_slice($idx, 1, 6))));
            $rows[] = ['vlan' => (int) $idx[0], 'mac' => $mac, 'port' => $port];
        }

        return $rows;
    }

    /** @return array<int,int> bridgePort => ifIndex */
    public static function parsePortIfIndex(string $out): array
    {
        $map = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/\.(\d+)\s*=\s*(?:\w+:\s*)?(\d+)\s*$/', trim($line), $m)) {
                $map[(int) $m[1]] = (int) $m[2];
            }
        }

        return $map;
    }

    /** jnxExVlanTag: @return array<int,int> vlanIndex => 802.1Q tag (tag 0 skipped). */
    public static function parseVlanTags(string $out): array
    {
        $map = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/\.(\d+)\s*=\s*(?:\w+32?:\s*)?(\d+)\s*$/', trim($line), $m) && (int) $m[2] > 0) {
                $map[(int) $m[1]] = (int) $m[2];
            }
        }

        return $map;
    }

    /** jnxL2ald VLAN name column: @return array<int,string> vlanIndex => name. */
    public static function parseVlanNames(string $out): array
    {
        $map = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/\.(\d+)\s*=\s*(?:STRING:\s*)?"?([^"\r]+?)"?\s*$/', trim($line), $m)) {
                $name = trim($m[2]);
                if ($name !== '' && ! str_contains($name, 'No Such')) {
                    $map[(int) $m[1]] = $name;
                }
            }
        }

        return $map;
    }
}
