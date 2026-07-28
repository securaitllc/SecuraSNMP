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
    private const OID_SP_MODEL = '.1.3.6.1.4.1.23867.3.1.1.1.2.0';    // spsSystemModel
    private const OID_SP_VERSION = '.1.3.6.1.4.1.23867.3.1.1.1.1.0';  // spsSystemSWVersion
    private const OID_SP_SERIAL = '.1.3.6.1.4.1.23867.3.1.1.1.6.0';   // spsSystemSerial

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
        if ($this->isSet($device->model)) {
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

        $update = [];
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
        // The serial OID returns a dashed MAC (00-1B-BC-2F-58-30); the appliance's
        // own GUI shows it without separators (001BBC2F5830) — store that form.
        $serial = $this->firstNonEmpty(($this->walker)($device, self::OID_SP_SERIAL));
        if ($serial !== null) {
            $serial = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $serial));
        }

        return [
            $this->firstNonEmpty(($this->walker)($device, self::OID_SP_MODEL)),
            $serial,
            $this->firstNonEmpty(($this->walker)($device, self::OID_SP_VERSION)),
        ];
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
