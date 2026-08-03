<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Support\MacOui;
use Illuminate\Support\Carbon;

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

        $portToIf = self::parsePortIfIndex(($this->walker)($device, self::OID_PORT_IFIDX));
        $ifByIndex = DeviceInterface::where('device_id', $device->id)->pluck('id', 'if_index');

        $count = 0;
        foreach ($fdb as $e) {
            $mac = MacOui::normalize($e['mac']);
            if ($mac === null) {
                continue;
            }
            $ifIndex = $portToIf[$e['port']] ?? null;

            $row = MacAddress::firstOrNew(['device_id' => $device->id, 'mac' => $mac, 'vlan' => $e['vlan']]);
            if (! $row->exists) {
                $row->first_seen_at = $now;
            }
            $row->device_interface_id = $ifIndex !== null ? ($ifByIndex[$ifIndex] ?? null) : null;
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
}
