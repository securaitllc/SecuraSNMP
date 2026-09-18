<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAlarm;

/**
 * Raises alarms for FortiGate firewalls (FORTINET-FORTIGATE-MIB, enterprise 12356).
 *
 * Until now the three firewalls in the fleet only ever produced one kind of alarm
 * between them: `device-unreachable`, from ICMP. Nothing about the firewall itself
 * was monitored — an IPsec tunnel could drop, a cluster member could fall out of
 * sync, or the box could sit in conserve mode, and Nodus had nothing to say.
 *
 * Unlike EdgeConnect, a FortiGate exposes no active-alarm table: there is no list
 * of faults to read. Alarms here are DERIVED from state — a tunnel whose status
 * says down, a cluster member that stopped syncing, memory above the conserve-mode
 * line — and reconciled the same way, so an alarm clears when the state it was
 * derived from goes away.
 *
 * Two rules carried over from the rest of this codebase:
 *   - A failed walk is not a healthy answer. No sysUpTime reply means the poll
 *     failed and NOTHING is changed, so an unreachable firewall never false-clears.
 *   - An absent OID is not a good reading. A FortiOS build that does not answer a
 *     scalar yields no alarm and no clear for that check — unmeasured stays
 *     unmeasured rather than counting as healthy.
 */
class FortiGateAlarmPoller
{
    private const OID_SYS_UPTIME = '.1.3.6.1.2.1.1.3.0';

    // fgSystemInfo scalars.
    private const OID_CPU = '.1.3.6.1.4.1.12356.101.4.1.3.0';        // fgSysCpuUsage (%)

    private const OID_MEMORY = '.1.3.6.1.4.1.12356.101.4.1.4.0';     // fgSysMemUsage (%)

    private const OID_DISK_USED = '.1.3.6.1.4.1.12356.101.4.1.6.0';  // fgSysDiskUsage (MB)

    private const OID_DISK_CAP = '.1.3.6.1.4.1.12356.101.4.1.7.0';   // fgSysDiskCapacity (MB)

    private const OID_SESSIONS = '.1.3.6.1.4.1.12356.101.4.1.8.0';   // fgSysSesCount

    // fgVpnTunnelTable — one row per phase-2 selector.
    private const OID_VPN_NAME = '.1.3.6.1.4.1.12356.101.12.2.2.1.2';    // fgVpnTunEntPhase1Name

    private const OID_VPN_P2NAME = '.1.3.6.1.4.1.12356.101.12.2.2.1.3';  // fgVpnTunEntPhase2Name

    private const OID_VPN_REMOTE = '.1.3.6.1.4.1.12356.101.12.2.2.1.5';  // fgVpnTunEntRemGwyIp

    private const OID_VPN_STATUS = '.1.3.6.1.4.1.12356.101.12.2.2.1.20'; // 1=down 2=up

    // fgHaStatsTable — one row per cluster member.
    private const OID_HA_SERIAL = '.1.3.6.1.4.1.12356.101.13.2.1.1.2';       // fgHaStatsSerial

    private const OID_HA_SYNC = '.1.3.6.1.4.1.12356.101.13.2.1.1.12';       // fgHaStatsSyncStatus 0=out 1=in

    public const ALARM_PREFIX = 'fg:';

    /**
     * Memory at or above this is conserve-mode territory on FortiOS — the box starts
     * refusing sessions, so it is a NOC event rather than a statistic.
     */
    public const MEMORY_WARN_PCT = 88;

    /** A log disk this full stops recording, which is how an incident loses its evidence. */
    public const DISK_WARN_PCT = 90;

    /** CPU is spiky by nature; only a sustained pin is worth waking someone for. */
    public const CPU_WARN_PCT = 95;

    /** @param callable(Device, string): string $walker Returns raw snmpwalk stdout for an OID. */
    public function __construct(private $walker) {}

    public function poll(Device $device): void
    {
        // Reachability guard — a failed poll must change nothing at all.
        if (trim(($this->walker)($device, self::OID_SYS_UPTIME)) === '') {
            return;
        }

        $seen = [];

        foreach ($this->vpnAlarms($device) as [$id, $description, $severity]) {
            $this->raise($device, $id, $description, $severity);
            $seen[] = $id;
        }

        foreach ($this->haAlarms($device) as [$id, $description, $severity]) {
            $this->raise($device, $id, $description, $severity);
            $seen[] = $id;
        }

        foreach ($this->resourceAlarms($device) as [$id, $description, $severity]) {
            $this->raise($device, $id, $description, $severity);
            $seen[] = $id;
        }

        $this->clearMissing($device, $seen);
    }

    /**
     * An IPsec tunnel reporting down.
     *
     * This is the check the fleet most needs: a site-to-site tunnel dropping was
     * previously invisible unless the firewall itself stopped answering ping.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function vpnAlarms(Device $device): array
    {
        $walk = fn (string $oid) => HealthPoller::parseWalk(($this->walker)($device, $oid));

        $status = $walk(self::OID_VPN_STATUS);
        if ($status === []) {
            return [];    // no VPN table on this build, or the walk failed — say nothing
        }

        $p1 = $walk(self::OID_VPN_NAME);
        $p2 = $walk(self::OID_VPN_P2NAME);
        $remote = $walk(self::OID_VPN_REMOTE);

        $out = [];
        foreach ($status as $index => $value) {
            $value = trim($value);
            // Only an explicit "down" raises. An unparsable or absent status is
            // unknown, and unknown must not read as either state.
            if (! is_numeric($value) || (int) $value !== 1) {
                continue;
            }

            $name = trim($p2[$index] ?? '') ?: trim($p1[$index] ?? '') ?: "tunnel {$index}";
            $peer = trim($remote[$index] ?? '');
            $where = $peer !== '' ? " to {$peer}" : '';

            $out[] = [
                self::ALARM_PREFIX."vpn:{$name}",
                "IPsec tunnel down — {$name}{$where}",
                'critical',
            ];
        }

        return $out;
    }

    /**
     * A cluster member that has stopped synchronising.
     *
     * An HA pair that is out of sync still passes traffic, so nothing looks wrong
     * until the failover that does not work.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function haAlarms(Device $device): array
    {
        $walk = fn (string $oid) => HealthPoller::parseWalk(($this->walker)($device, $oid));

        $sync = $walk(self::OID_HA_SYNC);
        if ($sync === []) {
            return [];    // standalone unit, or no HA MIB — not a fault
        }

        $serials = $walk(self::OID_HA_SERIAL);

        $out = [];
        foreach ($sync as $index => $value) {
            $value = trim($value);
            if (! is_numeric($value) || (int) $value !== 0) {
                continue;
            }

            $serial = trim($serials[$index] ?? '') ?: "member {$index}";

            $out[] = [
                self::ALARM_PREFIX."ha:{$serial}",
                "HA cluster member out of sync — {$serial}",
                'critical',
            ];
        }

        return $out;
    }

    /**
     * Memory, disk and CPU against their thresholds.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function resourceAlarms(Device $device): array
    {
        $out = [];

        $memory = $this->scalar($device, self::OID_MEMORY);
        if ($memory !== null && $memory >= self::MEMORY_WARN_PCT) {
            $out[] = [
                self::ALARM_PREFIX.'sys:memory',
                "Memory {$memory}% — at or above the conserve-mode threshold (".self::MEMORY_WARN_PCT.'%)',
                'warning',
            ];
        }

        $cpu = $this->scalar($device, self::OID_CPU);
        if ($cpu !== null && $cpu >= self::CPU_WARN_PCT) {
            $out[] = [
                self::ALARM_PREFIX.'sys:cpu',
                "CPU {$cpu}%",
                'warning',
            ];
        }

        // Disk arrives as used/total in MB rather than a percentage.
        $used = $this->scalar($device, self::OID_DISK_USED);
        $capacity = $this->scalar($device, self::OID_DISK_CAP);
        if ($used !== null && $capacity !== null && $capacity > 0) {
            $pct = (int) round($used / $capacity * 100);
            if ($pct >= self::DISK_WARN_PCT) {
                $out[] = [
                    self::ALARM_PREFIX.'sys:disk',
                    "Log disk {$pct}% full — {$used} of {$capacity} MB",
                    'warning',
                ];
            }
        }

        return $out;
    }

    /** A numeric scalar, or null when the agent did not answer with one. */
    private function scalar(Device $device, string $oid): ?int
    {
        $raw = ($this->walker)($device, $oid);
        if (! preg_match('/=\s*(?:[A-Za-z0-9-]+:\s*)?(\d+)/', $raw, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /** Open or re-open an alarm, honouring a manual clear exactly as EdgeConnect does. */
    private function raise(Device $device, string $alarmId, string $description, string $severity): void
    {
        $alarm = DeviceAlarm::firstOrNew(['device_id' => $device->id, 'alarm_id' => $alarmId]);
        $alarm->description = $description;
        $alarm->severity = $severity;

        if (! $alarm->exists) {
            $alarm->first_seen_at = now();
            $alarm->cleared_at = null;
        } elseif ($alarm->cleared_at !== null && ! $alarm->active_on_device) {
            // Cleared, and the firewall had stopped reporting it — a genuine flap.
            // Fresh ticket so the recurrence is countable.
            $alarm->ticket_number = DeviceAlarm::generateTicketNumber();
            $alarm->first_seen_at = now();
            $alarm->cleared_at = null;
            $alarm->cleared_by = null;
            $alarm->clear_note = null;
            $alarm->cleared_manually = false;
            $alarm->acknowledged_at = null;
            $alarm->acknowledged_by = null;
            $alarm->ack_note = null;
        }
        // Otherwise: still open (leave it), or manually cleared while the condition
        // persists — respect the NOC's clear and do not resurrect it.

        $alarm->active_on_device = true;
        $alarm->save();
    }

    /** Clear the FortiGate alarms this device is no longer reporting. */
    private function clearMissing(Device $device, array $seen): void
    {
        DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', 'like', self::ALARM_PREFIX.'%')
            ->whereNull('cleared_at')
            ->when($seen !== [], fn ($q) => $q->whereNotIn('alarm_id', $seen))
            ->get()
            ->each(function (DeviceAlarm $alarm) {
                $alarm->cleared_at = now();
                $alarm->active_on_device = false;
                $alarm->save();
            });

        // A condition that has gone away must also stop counting as "on the device",
        // or a later re-occurrence would not be recognised as a flap.
        DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', 'like', self::ALARM_PREFIX.'%')
            ->whereNotNull('cleared_at')
            ->when($seen !== [], fn ($q) => $q->whereNotIn('alarm_id', $seen))
            ->update(['active_on_device' => false]);
    }
}
