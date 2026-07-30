<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceMetricHistory;

/**
 * Enriches a device's hardware identity (model, serial number, OS version) over
 * SNMP. The device-reachability monitor is ICMP-only and the health poller only
 * reads utilisation counters, so nothing else fills these in — an imported
 * switch keeps its placeholder model ("Unknown") forever without this.
 *
 * Reads the vendor-neutral ENTITY-MIB first (entPhysicalModelName /
 * entPhysicalSerialNum), then falls back to parsing sysDescr, which on Junos
 * carries both the chassis model and the JUNOS version.
 *
 * Only blank/placeholder fields are written, so a value set by hand or by
 * discovery is never clobbered.
 */
class SnmpIdentityPoller
{
    private const OID_SYS_DESCR = '.1.3.6.1.2.1.1.1';                 // sysDescr
    private const OID_ENT_MODEL = '.1.3.6.1.2.1.47.1.1.1.1.13';       // entPhysicalModelName
    private const OID_ENT_SERIAL = '.1.3.6.1.2.1.47.1.1.1.1.11';      // entPhysicalSerialNum

    // Silver Peak EdgeConnect (ECOS) does not populate ENTITY-MIB — those OIDs
    // return "No Such Object". Its identity lives under the SILVERPEAK-MGMT-MIB
    // system group (the same vendor OIDs the interface poller already uses).
    // The whole system group in one walk — spsSystemSWVersion(.1), model(.2),
    // serial(.6) arrive together, so a flaky appliance can't return the model while
    // dropping the version scalar.
    private const OID_SP_GROUP = '.1.3.6.1.4.1.23867.3.1.1.1';

    /** @param callable(Device, string): string $walker Returns raw snmpwalk stdout for an OID. */
    public function __construct(private $walker)
    {
    }

    public function poll(Device $device): void
    {
        // Identity enrichment is triggered by a MISSING model — the primary
        // identity field. Serial and OS version are grabbed opportunistically in
        // that same pass, but are best-effort: many devices simply don't expose
        // them, so we must NOT keep re-walking every cycle chasing a field the
        // agent will never return. Once the model is known, this device is done.
        //
        // (This guard is the fix for a health-loop snmpwalk storm: requiring all
        // three fields meant every device lacking a serial or OS re-ran 3 blocking
        // walks every cycle forever, saturating the box.)
        //
        // Exception: an EdgeConnect that has a model but no os_version. Its version
        // scalar is flaky, so it was captured as model-only and then never retried —
        // 113 of 130 appliances sat unassessable. Keep polling silverpeak until the
        // version lands (one cheap group walk + a sysDescr fallback that always
        // answers), after which model+version are set and it is done for good.
        // Identity is complete only when model, serial AND OS are all known. It used
        // to be gated on the MODEL alone, which meant a model typed into the add form
        // made the poller think it was done and return before ever walking for the
        // serial or OS — a hand-entered model silently disabled discovery. Now we keep
        // trying until every field lands (or the agent clearly won't give it).
        $identityDone = $this->isSet($device->model)
            && $this->isSet($device->serial_number)
            && $this->isSet($device->os_version);
        if ($identityDone) {
            return;
        }

        // Throttle: a device that never exposes a serial/OS (some don't) must not
        // re-walk every 5-min cycle forever — that's the snmpwalk storm the old
        // model-only gate guarded against. Retry incomplete identity at most every 6h.
        if ($device->identity_attempted_at && $device->identity_attempted_at->gt(now()->subHours(6))) {
            return;
        }

        // Don't spend blocking SNMP walks on a device that isn't answering ICMP —
        // it would just time out. Retry once it's reachable again. (A device never
        // yet pinged has no row and is treated as not-yet-reachable.)
        $lastPing = DeviceMetricHistory::where('device_id', $device->id)
            ->latest('recorded_at')
            ->value('response_time_ms');
        if ($lastPing === null) {
            return;
        }

        if ($device->vendor === 'silverpeak') {
            [$model, $serial, $osVersion] = $this->silverpeakIdentity($device);
        } else {
            $sysDescr = trim(implode(' ', HealthPoller::parseWalk(($this->walker)($device, self::OID_SYS_DESCR))));
            $model = $this->firstNonEmpty(($this->walker)($device, self::OID_ENT_MODEL)) ?? $this->modelFromSysDescr($sysDescr);
            $serial = $this->firstNonEmpty(($this->walker)($device, self::OID_ENT_SERIAL));
            $osVersion = $this->osFromSysDescr($sysDescr);
        }

        // Stamp the attempt so the throttle above can pace the next retry.
        $update = ['identity_attempted_at' => now()];
        if (! $this->isSet($device->model) && $this->isSet($model)) {
            $update['model'] = $model;
        }
        if (! $this->isSet($device->serial_number) && $this->isSet($serial)) {
            $update['serial_number'] = $serial;
        }
        if (! $this->isSet($device->os_version) && $this->isSet($osVersion)) {
            $update['os_version'] = $osVersion;
        }

        if ($update !== []) {
            $device->forceFill($update)->save();
        }
    }

    /** A value counts as unset when it's null, blank, or the "Unknown" import placeholder. */
    private function isSet(?string $v): bool
    {
        return $v !== null && trim($v) !== '' && strtolower(trim($v)) !== 'unknown';
    }

    /**
     * Silver Peak EdgeConnect identity — model, serial and ECOS version from the
     * SILVERPEAK-MGMT-MIB system group (ECOS does not populate ENTITY-MIB).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [model, serial, osVersion]
     */
    private function silverpeakIdentity(Device $device): array
    {
        // One walk of the SILVERPEAK-MGMT system group. Reading the three scalars
        // separately let a device answer the model but silently drop the version
        // scalar under high memory — which left 113 of 130 EdgeConnects with a model
        // but no os_version in production.
        $rows = $this->indexBySuffix(($this->walker)($device, self::OID_SP_GROUP));

        // The serial OID returns a dashed MAC (00-1B-BC-2F-58-30); the appliance's
        // own GUI shows it without separators (001BBC2F5830) — store that form.
        $serial = $rows['6'] ?? null;
        if ($serial !== null) {
            $serial = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $serial));
        }

        // Fallback: the ECOS release is also carried in sysDescr ("… ECOS
        // 9.3.8.1_96913 …"), the standard OID these appliances answer reliably even
        // when the enterprise scalar is dropped — so version stops going missing.
        $version = $rows['1'] ?? null;
        if (! $this->isSet($version)) {
            $sysDescr = trim(implode(' ', HealthPoller::parseWalk(($this->walker)($device, self::OID_SYS_DESCR))));
            if (preg_match('/ECOS\s+([\w.\-]+)/i', $sysDescr, $m)) {
                $version = $m[1];
            }
        }

        return [$rows['2'] ?? null, $serial, $version];
    }

    /**
     * Map SILVERPEAK-MGMT system-group rows to suffix => value, e.g. the ".1.0" row
     * (version) to "1". Skips SNMP "not found" sentinels.
     *
     * @return array<string, string>
     */
    private function indexBySuffix(string $walk): array
    {
        $out = [];
        foreach (explode("\n", $walk) as $line) {
            if (preg_match('/23867\.3\.1\.1\.1\.(\d+)\.0\s*=\s*\w[\w-]*:\s*"?(.*?)"?\s*$/', $line, $m)) {
                $value = trim($m[2]);
                if ($value !== '' && ! $this->isSnmpError($value)) {
                    $out[$m[1]] = $value;
                }
            }
        }

        return $out;
    }

    /** First non-empty, non-error value in a walked table (the chassis row on most gear). */
    private function firstNonEmpty(string $walk): ?string
    {
        foreach (HealthPoller::parseWalk($walk) as $v) {
            $v = trim($v);
            // An agent that lacks the OID answers with a sentinel string, not an
            // empty row — never treat that as a real value.
            if ($v !== '' && ! $this->isSnmpError($v)) {
                return $v;
            }
        }

        return null;
    }

    /** True for SNMP "not found" sentinels (No Such Object/Instance, end of MIB). */
    private function isSnmpError(string $v): bool
    {
        return (bool) preg_match('/No Such (Object|Instance)|No more variables|End of MIB/i', $v);
    }

    /**
     * Junos sysDescr: "Juniper Networks, Inc. ex4300-48t Ethernet Switch, kernel
     * JUNOS 18.4R2-S3.3 ...". The model is the token after the vendor phrase.
     */
    private function modelFromSysDescr(string $sysDescr): ?string
    {
        if (preg_match('/Juniper Networks,?\s*Inc\.?\s+([A-Za-z0-9][\w-]+)/i', $sysDescr, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /** Pull the JUNOS release string ("18.4R2-S3.3") out of sysDescr. */
    private function osFromSysDescr(string $sysDescr): ?string
    {
        if (preg_match('/JUNOS[:\s]+([\w.\-]+)/i', $sysDescr, $m)) {
            return rtrim($m[1], '.,');
        }

        return null;
    }
}
