<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceMember;

/**
 * Reads the members of a Juniper Virtual Chassis over SNMP and reconciles them into
 * `device_members`. A VC presents as ONE managed device (one IP) but is physically
 * several EX/QFX switches, each with its own serial number and role. Capturing every
 * member gives inventory/RMA all the serials, and — because an offline member simply
 * drops out of the VC member table — lets the NOC see a single dead member while the
 * chassis is still up on its management IP.
 *
 * Source: JUNIPER-VIRTUALCHASSIS-MIB jnxVirtualChassisMemberTable
 *   entry  1.3.6.1.4.1.2636.3.40.1.4.1.1.1
 *   .2 serial  .3 role(master/backup/linecard)  .5 sw version  .6 priority  .8 model
 * The trailing OID index is the member id (0–9).
 *
 * The walker is injected so the parse/reconcile logic is unit-testable with a fake.
 */
class VcMemberPoller
{
    private const ENTRY = '.1.3.6.1.4.1.2636.3.40.1.4.1.1.1';
    private const COL_SERIAL = '2';
    private const COL_ROLE = '3';
    private const COL_SWVER = '5';
    private const COL_PRIORITY = '6';
    private const COL_MODEL = '8';

    /** jnxVirtualChassisMemberRole enum → label. Per the MIB: master(1), backup(2), linecard(3). */
    private const ROLE = [1 => 'master', 2 => 'backup', 3 => 'linecard'];

    /** @param callable(Device, string): string $walker Returns raw snmpwalk stdout for an OID. */
    public function __construct(private $walker)
    {
    }

    public function poll(Device $device): void
    {
        // VC is a Juniper switch/router concept only — never walk it on EdgeConnect,
        // FortiGate, etc. (saves a blocking SNMP walk on gear that can't answer it).
        if ($device->vendor !== 'juniper' || ! in_array($device->role, ['switch', 'router'], true)) {
            return;
        }

        $serials = $this->indexBySuffix(($this->walker)($device, self::ENTRY.'.'.self::COL_SERIAL));

        // Empty read = either not a Virtual Chassis (a standalone switch) or the box
        // dropped the SNMP response this cycle. Either way NEVER reconcile members to
        // missing on an empty walk — that would flap every member offline on one lost
        // packet. Members only go missing when the walk returns OTHER members.
        if ($serials === []) {
            return;
        }

        $roles = $this->indexBySuffix(($this->walker)($device, self::ENTRY.'.'.self::COL_ROLE));
        $models = $this->indexBySuffix(($this->walker)($device, self::ENTRY.'.'.self::COL_MODEL));
        $swVersions = $this->indexBySuffix(($this->walker)($device, self::ENTRY.'.'.self::COL_SWVER));
        $priorities = $this->indexBySuffix(($this->walker)($device, self::ENTRY.'.'.self::COL_PRIORITY));

        $seen = [];
        foreach ($serials as $memberId => $serial) {
            $seen[] = $memberId;
            DeviceMember::updateOrCreate(
                ['device_id' => $device->id, 'member_id' => $memberId],
                [
                    'serial_number' => $this->clean($serial),
                    'model' => $this->clean($models[$memberId] ?? null),
                    'role' => $this->role($roles[$memberId] ?? null),
                    'sw_version' => $this->clean($swVersions[$memberId] ?? null),
                    'priority' => isset($priorities[$memberId]) && is_numeric($priorities[$memberId])
                        ? (int) $priorities[$memberId]
                        : null,
                    'status' => 'present',
                    'last_seen_at' => now(),
                    'absent_since' => null,
                ],
            );
        }

        // A member we've seen before but that's no longer in the table = offline.
        // Keep the row (its serial is still the physical switch's, wanted for RMA) but
        // flag it so the device panel and topology can surface a degraded VC.
        DeviceMember::where('device_id', $device->id)
            ->whereNotIn('member_id', $seen)
            ->where('status', '!=', 'missing')
            ->update(['status' => 'missing', 'absent_since' => now()]);

        $this->backfillIdentity($device);
    }

    /**
     * Give the device its own serial / OS from the VC master when the box doesn't
     * populate ENTITY-MIB and overrides sysDescr (common on EX4650) — the VC member
     * table is the reliable source for both. Only fills what's missing.
     */
    private function backfillIdentity(Device $device): void
    {
        $master = $device->members()
            ->where('status', 'present')
            ->orderByRaw("CASE role WHEN 'master' THEN 0 WHEN 'backup' THEN 1 ELSE 2 END")
            ->orderBy('member_id')
            ->first();
        if ($master === null) {
            return;
        }

        $fill = [];
        if (! $this->isSet($device->serial_number) && $this->isSet($master->serial_number)) {
            $fill['serial_number'] = $master->serial_number;
        }
        if (! $this->isSet($device->os_version) && $this->isSet($master->sw_version)) {
            $fill['os_version'] = $master->sw_version;
        }
        if ($fill !== []) {
            $device->forceFill($fill)->save();
        }
    }

    private function isSet(?string $v): bool
    {
        return $v !== null && trim($v) !== '' && strtolower(trim($v)) !== 'unknown';
    }

    /** Map jnxVirtualChassisMemberRole → label; pass through a string the agent already resolved. */
    private function role(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return self::ROLE[(int) $raw] ?? null;
        }

        // A MIB-aware agent prints "master(0)" or "master" — keep just the word.
        return strtolower(preg_replace('/\(.*$/', '', trim($raw))) ?: null;
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }

    /**
     * Parse one column's snmpwalk into [memberId => value], keyed by the trailing OID
     * index. Handles quoted DisplayStrings and skips agent "No Such Object" sentinels.
     *
     * @return array<int, string>
     */
    private function indexBySuffix(string $walk): array
    {
        $out = [];
        foreach (explode("\n", $walk) as $line) {
            if (! preg_match('/\.(\d+)\s*=\s*[A-Za-z0-9-]+:\s*"?(.*?)"?\s*$/', trim($line), $m)) {
                continue;
            }
            $value = trim($m[2]);
            if ($value !== '' && ! $this->isSnmpError($value)) {
                $out[(int) $m[1]] = $value;
            }
        }

        return $out;
    }

    private function isSnmpError(string $v): bool
    {
        return (bool) preg_match('/No Such (Object|Instance)|No more variables|end of MIB/i', $v);
    }
}
