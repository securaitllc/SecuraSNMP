<?php

namespace App\Services;

use App\Models\Device;
use App\Models\LldpNeighbor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Discovers physical adjacencies over LLDP-MIB (SNMP), so the topology can draw
 * the REAL link between the Silver Peak and the switch (and switch↔switch)
 * instead of inferring it by co-location. A switch reports the Silver Peak as a
 * neighbor and vice-versa; we resolve each neighbor back to a monitored device
 * by its advertised system name.
 *
 * The walker MUST be run with numeric output (snmpwalk -On) so the row index is
 * deterministic to parse regardless of which MIBs are installed on the host.
 */
class LldpCollector
{
    // LLDP-MIB (iso.std 1.0.8802...).
    private const OID_LOC_PORT_ID = '.1.0.8802.1.1.2.1.3.7.1.3';   // lldpLocPortId, index = local port num
    private const OID_LOC_PORT_DESC = '.1.0.8802.1.1.2.1.3.7.1.4'; // lldpLocPortDesc = interface name (ge-0/0/x)
    private const OID_REM_SYSNAME = '.1.0.8802.1.1.2.1.4.1.1.9';   // lldpRemSysName
    private const OID_REM_PORT_ID = '.1.0.8802.1.1.2.1.4.1.1.7';   // lldpRemPortId
    private const OID_REM_PORT_DESC = '.1.0.8802.1.1.2.1.4.1.1.8'; // lldpRemPortDesc
    private const OID_REM_SYSDESC = '.1.0.8802.1.1.2.1.4.1.1.10';  // lldpRemSysDesc
    private const OID_REM_CHASSIS = '.1.0.8802.1.1.2.1.4.1.1.5';   // lldpRemChassisId
    private const OID_REM_CAP = '.1.0.8802.1.1.2.1.4.1.1.12';      // lldpRemSysCapEnabled
    private const OID_REM_MANADDR = '.1.0.8802.1.1.2.1.4.2.1.3';   // lldpRemManAddrIfId (index carries the advertised mgmt IP)
    private const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';          // IF-MIB ifName (ifIndex => ge-0/0/x)
    private const OID_BASE_PORT_IFINDEX = '.1.3.6.1.2.1.17.1.4.1.2'; // dot1dBasePortIfIndex (bridgePort => ifIndex)
    private const OID_STP_PORT_STATE = '.1.3.6.1.2.1.17.2.15.1.3';   // dot1dStpPortState (bridgePort => state)

    /**
     * @param  callable(Device, string): string  $walker  Raw `snmpwalk -On` stdout for an OID.
     */
    public function __construct(private $walker)
    {
    }

    public function discoverAll(): void
    {
        Device::whereNotNull('snmp_version')
            ->where(function ($q) {
                $q->where(fn ($v2c) => $v2c->where('snmp_version', 'v2c')->whereNotNull('snmp_community'))
                    ->orWhere(fn ($v3) => $v3->where('snmp_version', 'v3')
                        ->whereNotNull('snmp_v3_username')->whereNotNull('snmp_v3_auth_key')->whereNotNull('snmp_v3_priv_key'));
            })
            ->get()
            ->each(function (Device $device) {
                try {
                    $this->discover($device);
                } catch (Throwable $e) {
                    Log::warning("LLDP discovery failed for device {$device->id}: {$e->getMessage()}");
                }
            });
    }

    public function discover(Device $device): void
    {
        $walk = fn (string $oid): string => ($this->walker)($device, $oid);

        // A successful (even empty) walk means the device answered; only then do we
        // reconcile. A thrown walker (unreachable) aborts before any deletion.
        $localPorts = $this->parse($walk(self::OID_LOC_PORT_ID), self::OID_LOC_PORT_ID);
        $sysNames = $this->parse($walk(self::OID_REM_SYSNAME), self::OID_REM_SYSNAME);
        $portIds = $this->parse($walk(self::OID_REM_PORT_ID), self::OID_REM_PORT_ID);
        $portDescs = $this->parse($walk(self::OID_REM_PORT_DESC), self::OID_REM_PORT_DESC);
        $sysDescs = $this->parse($walk(self::OID_REM_SYSDESC), self::OID_REM_SYSDESC);
        $chassisIds = $this->parse($walk(self::OID_REM_CHASSIS), self::OID_REM_CHASSIS);
        $caps = $this->parse($walk(self::OID_REM_CAP), self::OID_REM_CAP);
        // IF-MIB ifName gives the real interface name (ge-0/0/5) keyed by ifIndex,
        // which is the LLDP local port number — the authoritative source when the
        // switch advertises a numeric port id or a free-text port description.
        $ifNames = $this->parse($walk(self::OID_IFNAME), self::OID_IFNAME);
        // Management IPs neighbours advertise (phone / AP addresses), keyed by the
        // neighbour's rem-table index (timeMark.localPortNum.remoteIndex).
        $manAddrs = $this->parseManAddr($walk(self::OID_REM_MANADDR), self::OID_REM_MANADDR);
        // Spanning-tree state per local port, resolved ifIndex => state via the
        // bridge-port map (dot1dStpPortState is keyed by bridge port, not ifIndex).
        // Lets the topology flag a link STP has blocked (a redundant path).
        $stpByIfIndex = [];
        $basePortIf = $this->parse($walk(self::OID_BASE_PORT_IFINDEX), self::OID_BASE_PORT_IFINDEX);
        $stpStates = $this->parse($walk(self::OID_STP_PORT_STATE), self::OID_STP_PORT_STATE);
        foreach ($basePortIf as $bridgePort => $ifIndex) {
            if (isset($stpStates[$bridgePort]) && is_numeric($stpStates[$bridgePort])) {
                $stpByIfIndex[$ifIndex] = (int) $stpStates[$bridgePort];
            }
        }

        $seen = [];
        foreach ($sysNames as $index => $remoteSys) {
            // lldpRem index = timeMark.localPortNum.remoteIndex
            $localNum = explode('.', $index)[1] ?? null;
            // The switch PORT the neighbour is on — only the interface name
            // (ge-0/0/5), never the human port description. Real ifName wins; else
            // an interface-looking lldpLocPortId; else the raw port number. The
            // lldpLocPortDesc (a free-text label like "TO WORKSTATIONS") is ignored.
            $localPort = ($localNum !== null ? ($ifNames[$localNum] ?? null) : null)
                ?? $this->preferIfName($localPorts[$localNum] ?? null, null)
                ?? $localNum;
            $remotePort = $this->preferIfName($portDescs[$index] ?? null, $portIds[$index] ?? null);
            $mgmtAddr = $manAddrs[$index] ?? null;
            $stpState = ($localNum !== null) ? ($stpByIfIndex[$localNum] ?? null) : null;
            $sysDesc = $sysDescs[$index] ?? null;
            $capHex = $caps[$index] ?? null;
            $remoteDevice = $this->resolveRemote($remoteSys);

            $neighbor = LldpNeighbor::updateOrCreate(
                [
                    'device_id' => $device->id,
                    'local_port' => $localPort,
                    'remote_sysname' => $remoteSys,
                    'remote_port' => $remotePort,
                ],
                [
                    'remote_sysdesc' => $sysDesc,
                    'remote_chassis_id' => $chassisIds[$index] ?? null,
                    'remote_capabilities' => $capHex,
                    'remote_mgmt_addr' => $mgmtAddr,
                    'stp_state' => $stpState,
                    'neighbor_type' => $this->classify($remoteSys, $sysDesc, $capHex, $remoteDevice !== null),
                    'remote_device_id' => $remoteDevice?->id,
                    'last_seen_at' => now(),
                ],
            );
            $seen[] = $neighbor->id;
        }

        // Drop neighbors that vanished from this device's table.
        LldpNeighbor::where('device_id', $device->id)
            ->whereNotIn('id', $seen ?: [0])
            ->delete();
    }

    /**
     * Classify an LLDP neighbor (Mist AP, PoE phone, switch, router, endpoint)
     * from its name/description and advertised LLDP capabilities. Heuristics on
     * the text are primary (vendor-portable); the capability bits back them up.
     */
    private function classify(string $sysName, ?string $sysDesc, ?string $capHex, bool $isManaged): string
    {
        $text = strtolower(trim($sysName.' '.($sysDesc ?? '')));

        if (preg_match('/\b(mist|access point|accesspoint|\bap\b|aruba|meraki|wireless)\b/', $text)) {
            return 'ap';
        }
        // Mitel MiNet IP phones advertise as "regDN 400100,MINET_6930"; add minet/regdn
        // and the 69xx model family to the usual VoIP vendor list.
        if (preg_match('/\b(phone|polycom|\bpoly\b|yealink|voip|grandstream|snom|avaya|mitel|minet|regdn|69\d{2})\b/', $text)) {
            return 'phone';
        }
        if (preg_match('/\b(cam|camera|axis|hikvision|dahua|avigilon|nvr)\b/', $text)) {
            return 'camera';
        }

        // Capability bits (lldpRemSysCapEnabled BITS, MSB-first in the first octet):
        // bit3=0x10 wlanAP, bit4=0x08 router, bit5=0x04 telephone, bit2=0x20 bridge.
        $byte = $this->firstHexByte($capHex);
        if ($byte !== null) {
            if ($byte & 0x10) {
                return 'ap';
            }
            if ($byte & 0x04) {
                return 'phone';
            }
            if ($byte & 0x08) {
                return 'router';
            }
            if ($byte & 0x20) {
                return 'switch';
            }
        }

        if ($isManaged || preg_match('/\b(switch|juniper|ex\d|fortigate|silverpeak|edgeconnect|catalyst|nexus)\b/', $text)) {
            return 'switch';
        }

        return 'other';
    }

    /**
     * Pick the value that reads as an interface name (e.g. ge-0/0/5) over a raw
     * ifIndex / port number / MAC. Falls back to the first non-empty value.
     */
    private function preferIfName(?string $a, ?string $b): ?string
    {
        // 1) a clean interface token (ge-0/0/8, wan0 — a name, no spaces),
        // 2) any interface-looking value (e.g. a verbose "wan0 uplink" descr),
        // 3) the first non-empty value.
        foreach ([$a, $b] as $v) {
            if ($v !== null && $v !== '' && $this->looksLikeIfName($v) && ! preg_match('/\s/', trim($v))) {
                return trim($v);
            }
        }
        // A verbose port descr like "ge-0/0/4 TO CORE" or "xe-0/1/3 : uplink":
        // keep only the interface token, drop the human description — the operator
        // wants the port connected to, not its label.
        foreach ([$a, $b] as $v) {
            if ($v !== null && $v !== '' && $this->looksLikeIfName($v)
                && preg_match('#^[A-Za-z]+[\w./:-]*\d#', trim($v), $m)) {
                return rtrim($m[0], '.:-');
            }
        }
        foreach ([$a, $b] as $v) {
            if ($v !== null && $v !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /** An interface name has letters AND digits and is not a MAC address. */
    private function looksLikeIfName(?string $v): bool
    {
        if ($v === null) {
            return false;
        }
        $v = trim($v);
        // MAC (aa:bb:.. / aa bb .. / aa-bb-..) — a valid LLDP port id but not a name.
        if (preg_match('/^([0-9A-Fa-f]{2}[\s:.-]){2,}[0-9A-Fa-f]{2}$/', $v)) {
            return false;
        }

        return (bool) preg_match('/[a-zA-Z]/', $v) && (bool) preg_match('/\d/', $v);
    }

    /** First octet of an LLDP capability value like "00 28" / "0x0028" / "0028". */
    private function firstHexByte(?string $hex): ?int
    {
        if (! $hex) {
            return null;
        }
        if (preg_match('/([0-9A-Fa-f]{2})/', trim($hex), $m)) {
            return hexdec($m[1]);
        }

        return null;
    }

    /**
     * Map a neighbor's advertised system name back to a monitored device.
     */
    private function resolveRemote(string $sysName): ?Device
    {
        $name = trim($sysName);
        if ($name === '') {
            return null;
        }
        // LLDP often advertises an FQDN (host.corp.example.com); match the full
        // name, then the short hostname, then a management IP.
        $short = explode('.', $name)[0];

        return Device::whereRaw('LOWER(name) = ?', [strtolower($name)])->first()
            ?? Device::whereRaw('LOWER(name) = ?', [strtolower($short)])->first()
            ?? Device::where('ip_address', $name)->first();
    }

    /**
     * Parse the lldpRemManAddr table. Its OID index encodes the advertised IP:
     * timeMark.localPortNum.remoteIndex.addrSubtype.addrLen.<addr octets>. We
     * return [ "timeMark.localPortNum.remoteIndex" => "a.b.c.d" ] for IPv4, so a
     * neighbour row (keyed by that same 3-part index) can pick up its address.
     *
     * @return array<string, string>
     */
    private function parseManAddr(string $output, string $base): array
    {
        $out = [];
        $pattern = '/^'.preg_quote($base, '/').'\.([\d.]+)\s*=/';
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }
            $c = explode('.', $m[1]);
            // Need timeMark, localPortNum, remIndex, subtype(1=ipv4), len(4), 4 octets.
            if (count($c) < 9 || (int) $c[3] !== 1 || (int) $c[4] !== 4) {
                continue;
            }
            $out[$c[0].'.'.$c[1].'.'.$c[2]] = implode('.', array_slice($c, 5, 4));
        }

        return $out;
    }

    /**
     * Parse numeric-OID snmpwalk output for one column into [subIndex => value],
     * where subIndex is the dotted index after the column's base OID.
     *
     * @return array<string, string>
     */
    private function parse(string $output, string $base): array
    {
        $values = [];
        $pattern = '/^'.preg_quote($base, '/').'\.([\d.]+)\s*=\s*(?:[\w-]+:\s*)?(.*)$/';

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (preg_match($pattern, $line, $m)) {
                $value = trim($m[2]);
                if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
                    $value = substr($value, 1, -1);
                }
                $values[$m[1]] = $value;
            }
        }

        return $values;
    }
}
