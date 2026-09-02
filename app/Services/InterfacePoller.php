<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceVlan;
use App\Models\InterfaceAlert;
use App\Models\InterfaceMetricHistory;
use App\Models\LldpNeighbor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class InterfacePoller
{
    private const OID_IF_DESCR = '.1.3.6.1.2.1.2.2.1.2';
    // ifName (ifXTable): the device's canonical port name. FortiGate exposes its
    // real port names here (wan1/internal1/dmz) while leaving ifDescr empty for
    // any port without a configured description — so it's the naming fallback.
    private const OID_IF_NAME = '.1.3.6.1.2.1.31.1.1.1.1';
    private const OID_IF_ADMIN_STATUS = '.1.3.6.1.2.1.2.2.1.7'; // ifAdminStatus
    private const OID_IF_OPER_STATUS = '.1.3.6.1.2.1.2.2.1.8';
    private const OID_IF_IN_OCTETS = '.1.3.6.1.2.1.2.2.1.10';
    private const OID_IF_OUT_OCTETS = '.1.3.6.1.2.1.2.2.1.16';
    private const OID_IF_IN_DISCARDS = '.1.3.6.1.2.1.2.2.1.13';
    private const OID_IF_OUT_DISCARDS = '.1.3.6.1.2.1.2.2.1.19';
    private const OID_IF_IN_ERRORS = '.1.3.6.1.2.1.2.2.1.14';  // ifInErrors (CRC/framing)
    private const OID_IF_OUT_ERRORS = '.1.3.6.1.2.1.2.2.1.20'; // ifOutErrors
    private const OID_IF_HIGH_SPEED = '.1.3.6.1.2.1.31.1.1.1.15'; // ifHighSpeed, Mbps
    private const OID_IF_SPEED = '.1.3.6.1.2.1.2.2.1.5';         // ifSpeed, bps (fallback)
    // EtherLike-MIB dot3StatsDuplexStatus (indexed by ifIndex): 1=unknown, 2=half,
    // 3=full. A half-duplex switch port or a speed downshift signals a cabling /
    // negotiation problem rather than the interface.
    private const OID_IF_DUPLEX = '.1.3.6.1.2.1.10.7.2.1.19';
    // Q-BRIDGE-MIB dot1qVlanStaticName: index is the VLAN id, value the name.
    // Standard on many switches; not universal across vendors (disclosed).
    private const OID_VLAN_STATIC_NAME = '.1.3.6.1.2.1.17.7.1.4.3.1.1';
    // JUNIPER-VLAN-MIB jnxExVlanTable (EX switches): jnxExVlanTag is the real
    // 802.1Q id, jnxExVlanName the name, both keyed by jnxExVlanIndex. This is the
    // authoritative VLAN source when present — Q-BRIDGE on Juniper keys by an
    // internal index that isn't the tag. (ELS switches don't expose this table.)
    private const OID_JNX_VLAN_TAG = '.1.3.6.1.4.1.2636.3.40.1.5.1.5.1.5';
    private const OID_JNX_VLAN_NAME = '.1.3.6.1.4.1.2636.3.40.1.5.1.5.1.2';
    // ENTITY-MIB entPhysicalSerialNum: serial per physical entity. The chassis
    // serial is the first non-empty value. Standard-ish, vendor-varying (disclosed).
    private const OID_ENT_SERIAL = '.1.3.6.1.2.1.47.1.1.1.1.11';
    // Silver Peak EdgeConnect exposes its serial at a vendor scalar OID instead.
    private const OID_SILVERPEAK_SERIAL = '.1.3.6.1.4.1.23867.3.1.1.1.6.0';
    // Juniper reports its chassis model as the entPhysicalName of the chassis
    // entity (index 120 on these switches), which is more accurate than sysDescr,
    // and the Junos OS release as entPhysicalSoftwareRev of the chassis (index 1).
    private const OID_JUNIPER_MODEL = '.1.3.6.1.2.1.47.1.1.1.1.7.120';
    private const OID_JUNIPER_OS_VERSION = '.1.3.6.1.2.1.47.1.1.1.1.10.1';
    private const OID_FORTIGATE_VERSION = '.1.3.6.1.4.1.12356.101.4.1.1.0'; // fgSysVersion

    /**
     * @param callable(Device, string): string $walker Returns raw snmpwalk stdout for the given OID.
     */
    public function __construct(private $walker)
    {
    }

    public function pollAll(): void
    {
        Device::whereNotNull('snmp_version')
            ->where(function ($query) {
                $query->where(function ($v2c) {
                    $v2c->where('snmp_version', 'v2c')->whereNotNull('snmp_community');
                })->orWhere(function ($v3) {
                    $v3->where('snmp_version', 'v3')
                        ->whereNotNull('snmp_v3_username')
                        ->whereNotNull('snmp_v3_auth_key')
                        ->whereNotNull('snmp_v3_priv_key');
                });
            })
            ->get()
            ->each(function (Device $device) {
                try {
                    $this->poll($device);
                } catch (Throwable $e) {
                    Log::error("Interface poll failed for device {$device->id}: {$e->getMessage()}");
                }
            });
    }

    public function poll(Device $device): void
    {
        $now = now();

        // Ports that LLDP shows connecting to another switch or an SD-WAN/router
        // are uplinks — their loss is a CRITICAL, segment-affecting fault. Fetch
        // the set once per device so the per-interface check is a cheap lookup.
        $uplinkPorts = $this->uplinkPorts($device);

        $names = $this->parseWalk(($this->walker)($device, self::OID_IF_DESCR));
        $ifNames = $this->parseWalk(($this->walker)($device, self::OID_IF_NAME));
        $statuses = $this->parseWalk(($this->walker)($device, self::OID_IF_OPER_STATUS));
        $adminStatuses = $this->parseWalk(($this->walker)($device, self::OID_IF_ADMIN_STATUS));
        $inOctets = $this->parseWalk(($this->walker)($device, self::OID_IF_IN_OCTETS));
        $outOctets = $this->parseWalk(($this->walker)($device, self::OID_IF_OUT_OCTETS));
        $inDiscards = $this->parseWalk(($this->walker)($device, self::OID_IF_IN_DISCARDS));
        $outDiscards = $this->parseWalk(($this->walker)($device, self::OID_IF_OUT_DISCARDS));
        $inErrors = $this->parseWalk(($this->walker)($device, self::OID_IF_IN_ERRORS));
        $outErrors = $this->parseWalk(($this->walker)($device, self::OID_IF_OUT_ERRORS));
        $highSpeed = $this->parseWalk(($this->walker)($device, self::OID_IF_HIGH_SPEED));
        $speed = $this->parseWalk(($this->walker)($device, self::OID_IF_SPEED));
        $duplexes = $this->parseWalk(($this->walker)($device, self::OID_IF_DUPLEX));

        // Drive the loop off ifOperStatus (every real interface reports one), NOT
        // ifDescr — FortiGate returns an EMPTY ifDescr for any port without a
        // configured description, which the parser drops, so keying on ifDescr
        // silently loses most of a firewall's ports. An interface missing from the
        // oper-status walk is still skipped (a truncated response, not a real port).
        foreach ($statuses as $ifIndex => $operRaw) {
            // Name: prefer the human ifDescr, fall back to the canonical ifName
            // (FortiGate's wan1/internal1/dmz live here), then a synthetic label so
            // an unnamed-but-real interface is tracked rather than dropped.
            $descr = trim($names[$ifIndex] ?? '');
            // The port's OWN name, kept whether or not it is the one displayed.
            // EdgeConnect puts a free-text comment in ifDescr ("BB," on a wan0
            // carrying a broadband circuit, ",Data" on a LAN sub-interface), so on
            // that gear ifDescr is a label and ifName is the only place the real
            // port name exists. Discarding it left circuits unable to find the
            // port they land on. Stored separately so nothing keyed on `if_name`
            // moves.
            $canonical = trim($ifNames[$ifIndex] ?? '') ?: null;
            $name = $descr !== '' ? $descr : ($canonical ?: "if{$ifIndex}");

            $status = $this->isOperStatusUp($operRaw) ? 'up' : 'down';

            // ifAdminStatus: an administratively-disabled port that reports
            // oper-down is intentional (an unused port), not a fault. Default to
            // 'up' when the device omits the row so behaviour is unchanged.
            $adminUp = array_key_exists($ifIndex, $adminStatuses)
                ? $this->isOperStatusUp($adminStatuses[$ifIndex])
                : true;
            $adminStatus = $adminUp ? 'up' : 'down';

            $interface = DeviceInterface::firstOrNew([
                'device_id' => $device->id,
                'if_index' => $ifIndex,
            ]);

            // A counter missing from its walk (partial response) keeps the
            // previously stored value instead of resetting to 0, so the delta
            // for that metric comes out as 0 this cycle rather than a false spike.
            $newInOctets = array_key_exists($ifIndex, $inOctets) ? (int) $inOctets[$ifIndex] : (int) ($interface->in_octets ?? 0);
            $newOutOctets = array_key_exists($ifIndex, $outOctets) ? (int) $outOctets[$ifIndex] : (int) ($interface->out_octets ?? 0);
            $newInDiscards = array_key_exists($ifIndex, $inDiscards) ? (int) $inDiscards[$ifIndex] : (int) ($interface->in_discards ?? 0);
            $newOutDiscards = array_key_exists($ifIndex, $outDiscards) ? (int) $outDiscards[$ifIndex] : (int) ($interface->out_discards ?? 0);
            $newInErrors = array_key_exists($ifIndex, $inErrors) ? (int) $inErrors[$ifIndex] : (int) ($interface->in_errors ?? 0);
            $newOutErrors = array_key_exists($ifIndex, $outErrors) ? (int) $outErrors[$ifIndex] : (int) ($interface->out_errors ?? 0);

            // Treat a newly-discovered interface as already being in its current
            // state, so a port first seen down (an unused/unpatched port) is NOT
            // reported as a fresh "interface down" — only a real up->down flap is.
            // Same for a GAP in polling (the poller was starved/restarted): the stored
            // state is stale, so re-baseline instead of firing a false flap for a
            // change that happened while we weren't looking. 10 min ≫ the poll interval.
            $pollingGap = $interface->exists
                && $interface->last_polled_at
                && $interface->last_polled_at->lt($now->copy()->subSeconds(600));
            $wasUp = ($interface->exists && ! $pollingGap) ? $interface->status === 'up' : ($status === 'up');
            $inOctetsDelta = $interface->exists ? max(0, $newInOctets - $interface->in_octets) : 0;
            $outOctetsDelta = $interface->exists ? max(0, $newOutOctets - $interface->out_octets) : 0;
            $inDiscardsDelta = $interface->exists ? max(0, $newInDiscards - $interface->in_discards) : 0;
            $outDiscardsDelta = $interface->exists ? max(0, $newOutDiscards - $interface->out_discards) : 0;
            $inErrorsDelta = $interface->exists ? max(0, $newInErrors - $interface->in_errors) : 0;
            $outErrorsDelta = $interface->exists ? max(0, $newOutErrors - $interface->out_errors) : 0;

            // Cheap health markers maintained inline so the interface panel can read
            // health off the row instead of scanning metric history: count real
            // status flaps and stamp the last time errors / discards were seen.
            $statusChanged = $interface->exists && ! $pollingGap && $interface->status !== $status;
            $flapCount = (int) ($interface->flap_count ?? 0) + ($statusChanged ? 1 : 0);
            $sawErrors = ($inErrorsDelta + $outErrorsDelta) > 0;
            $sawDiscards = ($inDiscardsDelta + $outDiscardsDelta) > 0;

            // Link speed: prefer ifHighSpeed (Mbps), fall back to ifSpeed (bps),
            // then to whatever was last known so a partial walk doesn't zero it.
            $mbps = (int) ($highSpeed[$ifIndex] ?? 0);
            $speedBps = $mbps > 0
                ? $mbps * 1_000_000
                : (int) ($speed[$ifIndex] ?? ($interface->speed_bps ?? 0));

            // Utilisation % of link speed since the previous poll.
            $intervalSeconds = $interface->exists && $interface->last_polled_at
                ? max(1, $interface->last_polled_at->diffInSeconds($now))
                : 0;
            $inUtil = $speedBps > 0 && $intervalSeconds > 0
                ? min(100, round($inOctetsDelta * 8 / ($speedBps * $intervalSeconds) * 100, 2))
                : 0;
            $outUtil = $speedBps > 0 && $intervalSeconds > 0
                ? min(100, round($outOctetsDelta * 8 / ($speedBps * $intervalSeconds) * 100, 2))
                : 0;

            // Link speed / duplex change tracking. Only a genuine change on an
            // existing interface (not a first sighting or a post-gap re-baseline)
            // is stamped — a duplex flip to half or a speed downshift is a strong
            // cabling/negotiation clue.
            $duplex = match ((int) ($duplexes[$ifIndex] ?? 0)) {
                3 => 'full',
                2 => 'half',
                1 => 'unknown',
                default => $interface->duplex,
            };
            $stable = $interface->exists && ! $pollingGap;
            $duplexChanged = $stable && $interface->duplex && $duplex && $duplex !== $interface->duplex;
            $speedShifted = $stable && (int) $interface->speed_bps > 0 && $speedBps > 0 && $speedBps !== (int) $interface->speed_bps;

            $interface->fill([
                'if_name' => $name,
                // Never blank an already-known name on a partial walk.
                'if_canonical_name' => $canonical ?: $interface->if_canonical_name,
                'status' => $status,
                'admin_status' => $adminStatus,
                'duplex' => $duplex,
                'prev_duplex' => $duplexChanged ? $interface->duplex : $interface->prev_duplex,
                'duplex_changed_at' => $duplexChanged ? $now : $interface->duplex_changed_at,
                'prev_speed_bps' => $speedShifted ? (int) $interface->speed_bps : $interface->prev_speed_bps,
                'speed_changed_at' => $speedShifted ? $now : $interface->speed_changed_at,
                // A port that comes back up is a live port again — drop any
                // false-alarm suppression so a real future outage still alarms.
                'alarm_suppressed' => $status === 'up' ? false : (bool) ($interface->alarm_suppressed ?? false),
                'in_octets' => $newInOctets,
                'out_octets' => $newOutOctets,
                'in_discards' => $newInDiscards,
                'out_discards' => $newOutDiscards,
                'in_discards_delta' => $inDiscardsDelta,
                'out_discards_delta' => $outDiscardsDelta,
                'in_errors' => $newInErrors,
                'out_errors' => $newOutErrors,
                'in_errors_delta' => $inErrorsDelta,
                'out_errors_delta' => $outErrorsDelta,
                'speed_bps' => $speedBps,
                'in_util_pct' => $inUtil,
                'out_util_pct' => $outUtil,
                'flap_count' => $flapCount,
                'last_flap_at' => $statusChanged ? $now : $interface->last_flap_at,
                'last_error_at' => $sawErrors ? $now : $interface->last_error_at,
                'last_discard_at' => $sawDiscards ? $now : $interface->last_discard_at,
                'last_polled_at' => now(),
            ])->save();

            InterfaceMetricHistory::create([
                'device_interface_id' => $interface->id,
                'recorded_at' => $now,
                'status' => $status,
                'in_octets_delta' => $inOctetsDelta,
                'out_octets_delta' => $outOctetsDelta,
                'in_discards_delta' => $inDiscardsDelta,
                'out_discards_delta' => $outDiscardsDelta,
                'in_errors_delta' => $inErrorsDelta,
                'out_errors_delta' => $outErrorsDelta,
            ]);

            // Only an administratively-enabled physical port that goes oper-down is
            // a fault worth alerting on. A disabled (unused) port never alerts, and
            // a logical sub-unit (ge-0/0/11.0) inherits its physical parent's state,
            // so it must not raise a second alarm for the same port.
            if ($adminUp && $wasUp && $status === 'down' && ! $this->isLogicalUnit($name)) {
                InterfaceAlert::create([
                    'device_interface_id' => $interface->id,
                    'severity' => $uplinkPorts->contains($this->portKey($name)) ? 'critical' : 'warning',
                    'started_at' => now(),
                ]);
            }

            // Close any open alert when the port recovers, is disabled, or is a
            // logical sub-unit (dedupe — the physical port keeps the alarm).
            // Recovery is LEVEL-triggered (status is up now), not edge-triggered on
            // (! $wasUp): if the port recovered DURING a poller-restart gap, $wasUp
            // was re-baselined to the current up state, so the old edge test never
            // fired and the alert hung open on the Alarms page forever. Same fix
            // CircuitMonitor got for its stuck-open alerts.
            if (! $adminUp || $status === 'up' || $this->isLogicalUnit($name)) {
                $interface->alerts()
                    ->whereNull('ended_at')
                    ->latest('started_at')
                    ->first()
                    ?->update(['ended_at' => now()]);
            }
        }

        $this->syncVlans($device, $now);
        $this->syncSerial($device);
        $this->syncJuniperInventory($device);
        $this->syncFortigateInventory($device);
    }

    /**
     * Discover the chassis model and Junos OS version for Juniper devices from
     * ENTITY-MIB. Each field is only updated when a non-empty value is found, so
     * a device that doesn't expose it keeps whatever it already has.
     */
    private function syncJuniperInventory(Device $device): void
    {
        if ($device->vendor !== 'juniper') {
            return;
        }

        $updates = [];

        if ($model = $this->firstValue($this->parseWalk(($this->walker)($device, self::OID_JUNIPER_MODEL)))) {
            if ($model !== $device->model) {
                $updates['model'] = $model;
            }
        }

        if ($version = $this->firstValue($this->parseWalk(($this->walker)($device, self::OID_JUNIPER_OS_VERSION)))) {
            if ($version !== $device->os_version) {
                $updates['os_version'] = $version;
            }
        }

        if ($updates !== []) {
            $device->update($updates);
        }
    }

    private function syncFortigateInventory(Device $device): void
    {
        if ($device->vendor !== 'fortigate') {
            return;
        }

        $version = $this->firstValue($this->parseWalk(($this->walker)($device, self::OID_FORTIGATE_VERSION)));
        if ($version && $version !== $device->os_version) {
            $device->update(['os_version' => $version]);
        }
    }

    /**
     * First non-empty trimmed value from a walk, or null.
     *
     * @param  array<int, string>  $walk
     */
    private function firstValue(array $walk): ?string
    {
        foreach ($walk as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Discover the chassis serial number from ENTITY-MIB. Only updates the
     * device when a non-empty serial is found, so a device that doesn't expose
     * the table keeps whatever it already has.
     */
    private function syncSerial(Device $device): void
    {
        // Silver Peak EdgeConnect uses a vendor OID; everything else ENTITY-MIB.
        if ($device->vendor === 'silverpeak') {
            foreach ($this->parseWalk(($this->walker)($device, self::OID_SILVERPEAK_SERIAL)) as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                // The appliance returns the serial as a dashed MAC (00-1B-BC-2F-58-30)
                // but its own GUI shows it without separators (001BBC2F5830) — store
                // the GUI form so they match. Fill when blank; also reformat when the
                // stored value is the SAME serial written differently, but never
                // clobber a genuinely different (hand-entered) value.
                $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
                $stored = (string) $device->serial_number;
                $storedClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $stored));
                if ($clean !== '' && $clean !== $stored && ($stored === '' || $storedClean === $clean)) {
                    $device->update(['serial_number' => $clean]);
                }

                return;
            }
        }

        $this->applyFirstSerial($device, $this->parseWalk(($this->walker)($device, self::OID_ENT_SERIAL)));
    }

    /**
     * Store the first non-empty serial from a walk. Returns true when a serial
     * was found (updated or already current), so the caller can stop.
     *
     * @param  array<int, string>  $serials
     */
    private function applyFirstSerial(Device $device, array $serials): bool
    {
        foreach ($serials as $serial) {
            $serial = trim($serial);
            if ($serial === '') {
                continue;
            }

            // Serial is immutable hardware identity — fill it once when blank, but
            // never clobber an existing value (so a hand-entered serial sticks).
            if (($device->serial_number ?? '') === '') {
                $device->update(['serial_number' => $serial]);
            }

            return true;
        }

        return false;
    }

    /**
     * Discover active VLANs from the switch's Q-BRIDGE VLAN table. If the walk
     * returns nothing (a device that doesn't expose the table, or a partial
     * response) the existing VLAN rows are left untouched rather than wiped.
     *
     * IMPORTANT — the Q-BRIDGE table INDEX (dot1qVlanIndex) is an INTERNAL index,
     * NOT the 802.1Q tag: on a switch where VLANs were created/deleted out of
     * order the index and the real tag diverge. Juniper works around this by
     * appending the real tag to the name ("MASSEY-WIFI+999"), so we take the tag
     * from that "+<tag>" suffix when present and store the clean name. Switches
     * that don't append it fall back to the OID index (where index == tag).
     */
    private function syncVlans(Device $device, $now): void
    {
        // 1) Authoritative Juniper source: jnxExVlanTag (real 802.1Q id) +
        //    jnxExVlanName, both keyed by jnxExVlanIndex. Only Juniper exposes it,
        //    so don't waste a walk on every other appliance in the fleet.
        if ($device->vendor === 'juniper') {
            $tags = $this->parseWalk(($this->walker)($device, self::OID_JNX_VLAN_TAG));
            if ($tags !== []) {
                $names = $this->parseWalk(($this->walker)($device, self::OID_JNX_VLAN_NAME));
                $pairs = [];
                foreach ($tags as $index => $tag) {
                    $tag = (int) $tag;
                    // Only real 802.1Q ids (1–4094); a reserved/internal 0 is not a VLAN.
                    if ($tag >= 1 && $tag <= 4094) {
                        $pairs[$tag] = trim($names[$index] ?? '');
                    }
                }
                if ($pairs !== []) {
                    $this->rememberVlanSource($device, 'jnx');
                    $this->upsertVlans($device, $now, $pairs);
                }

                return;
            }

            // This switch answered on the Juniper MIB before → an empty walk now is
            // a dropped SNMP response (normal under high memory), NOT a reason to
            // fall through to Q-BRIDGE and remap every VLAN to its internal index.
            // Leave the VLANs untouched this cycle.
            if ($device->vlan_source === 'jnx') {
                return;
            }
        }

        // 2) Fallback: Q-BRIDGE dot1qVlanStaticName. Some switches append the real
        //    tag to the name ("MASSEY-WIFI+999") — use it, else the OID index.
        $rows = $this->parseWalk(($this->walker)($device, self::OID_VLAN_STATIC_NAME));
        if (empty($rows)) {
            return;
        }
        $pairs = [];
        foreach ($rows as $index => $rawName) {
            [$vlanId, $name] = $this->resolveVlan((int) $index, (string) $rawName);
            $pairs[$vlanId] = $name;
        }
        $this->rememberVlanSource($device, 'qbridge');
        $this->upsertVlans($device, $now, $pairs);
    }

    /** Record (once) which source a switch answers VLANs on, to stop id-space flapping. */
    private function rememberVlanSource(Device $device, string $source): void
    {
        if ($device->vlan_source !== $source) {
            $device->update(['vlan_source' => $source]);
        }
    }

    /**
     * Upsert the device's active VLANs from a [tag => name] map and mark any VLAN
     * no longer reported inactive. A blank name (a dropped name-walk on the split
     * Juniper tag/name tables) never overwrites a previously-good name.
     *
     * @param  array<int, string>  $pairs
     */
    private function upsertVlans(Device $device, $now, array $pairs): void
    {
        foreach ($pairs as $vlanId => $name) {
            $attrs = ['status' => 'active', 'last_seen_at' => $now];
            if (trim((string) $name) !== '') {
                $attrs['name'] = $name;
            }
            DeviceVlan::updateOrCreate(
                ['device_id' => $device->id, 'vlan_id' => $vlanId],
                $attrs,
            );
        }

        DeviceVlan::where('device_id', $device->id)
            ->whereNotIn('vlan_id', array_keys($pairs) ?: [-1])
            ->update(['status' => 'inactive']);
    }

    /**
     * Resolve a Q-BRIDGE row to its real 802.1Q tag + clean name. The tag comes
     * from a trailing "+<tag>" the switch appends to the name (authoritative);
     * absent that, the OID index is used. Only a valid tag (1–4094) is trusted so
     * a name legitimately ending in "+<n>" for some other reason can't corrupt it.
     *
     * @return array{0:int,1:string} [vlanId, name]
     */
    private function resolveVlan(int $index, string $rawName): array
    {
        if (preg_match('/^(.*)\+(\d{1,4})$/', $rawName, $m)) {
            $tag = (int) $m[2];
            if ($tag >= 1 && $tag <= 4094) {
                return [$tag, trim($m[1])];
            }
        }

        return [$index, $rawName];
    }

    /** A logical sub-unit (ge-0/0/11.0, lan0.3) — inherits its physical parent's
     *  state, so it must not raise its own interface-down alarm. */
    private function isLogicalUnit(string $name): bool
    {
        return (bool) preg_match('/\.\d+$/', $name);
    }

    /**
     * The set of this device's local ports that LLDP shows facing another switch,
     * router, or SD-WAN appliance — i.e. uplinks. A neighbour we also manage
     * (remote_device_id set) or one that advertises a switch/router/edgeconnect
     * capability counts. Returned as normalised port keys for cheap lookup.
     */
    private function uplinkPorts(Device $device): Collection
    {
        return LldpNeighbor::where('device_id', $device->id)
            ->present()
            ->where(fn ($q) => $q
                ->whereNotNull('remote_device_id')
                ->orWhereIn('neighbor_type', ['switch', 'router', 'edgeconnect']))
            ->pluck('local_port')
            ->filter()
            ->map(fn ($p) => $this->portKey($p))
            ->unique()
            ->values();
    }

    /** Normalise a port name for matching (case/space-insensitive). */
    private function portKey(string $port): string
    {
        return strtolower(trim($port));
    }

    /**
     * Determines whether a raw ifOperStatus value represents the "up" state.
     *
     * snmpwalk's output form depends on whether MIB definitions are loaded on
     * the host running the binary:
     *   - MIB-resolved: "up(1)", "down(2)", "testing(3)", ...
     *   - Raw numeric (no MIBs loaded, today's production reality): "1", "2", ...
     *
     * Per IF-MIB ifOperStatus, only the numeric value 1 (up) counts as up;
     * every other value (2=down, 3=testing, 4=unknown, 5=dormant,
     * 6=notPresent, 7=lowerLayerDown) and anything unparseable is down.
     */
    private function isOperStatusUp(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/\((\d+)\)/', $value, $matches)) {
            return (int) $matches[1] === 1;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        // Last-resort fallback for a genuinely unparseable value.
        return str_contains($value, 'up');
    }

    private function parseWalk(string $output): array
    {
        $values = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/\.(\d+) = (?:STRING|INTEGER|Counter32|Counter64|Gauge32|Gauge): (.+)$/', trim($line), $matches)) {
                $value = trim($matches[2]);
                // Strip surrounding quotes from STRING values (e.g., "lo" -> lo)
                if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
                    $value = substr($value, 1, -1);
                }
                $values[(int) $matches[1]] = $value;
            }
        }

        return $values;
    }
}
