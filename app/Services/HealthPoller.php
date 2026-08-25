<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceHealth;
use App\Models\DeviceHealthHistory;
use App\Models\DeviceSensor;

/**
 * Polls cross-vendor device health over SNMP: CPU (HOST-RESOURCES
 * hrProcessorLoad), memory (hrStorage RAM), uptime (sysUpTime) and the
 * ENTITY-SENSOR table (temperature, fan RPM, voltage, power — covers temp/fan/PSU).
 *
 * The snmpwalk mechanics are injected as a callable, so the parsing/aggregation
 * logic here is deterministic and unit-testable (same pattern as InterfacePoller).
 */
class HealthPoller
{
    private const OID_CPU_LOAD = '.1.3.6.1.2.1.25.3.3.1.2';        // hrProcessorLoad
    private const OID_STORAGE_TYPE = '.1.3.6.1.2.1.25.2.3.1.2';   // hrStorageType
    private const OID_STORAGE_DESCR = '.1.3.6.1.2.1.25.2.3.1.3';  // hrStorageDescr
    private const OID_STORAGE_UNITS = '.1.3.6.1.2.1.25.2.3.1.4';  // hrStorageAllocationUnits
    private const OID_STORAGE_SIZE = '.1.3.6.1.2.1.25.2.3.1.5';   // hrStorageSize
    private const OID_STORAGE_USED = '.1.3.6.1.2.1.25.2.3.1.6';   // hrStorageUsed
    private const OID_SYS_UPTIME = '.1.3.6.1.2.1.1.3.0';          // sysUpTime
    private const OID_SENSOR_TYPE = '.1.3.6.1.2.1.99.1.1.1.1';    // entPhySensorType
    private const OID_SENSOR_PRECISION = '.1.3.6.1.2.1.99.1.1.1.3';
    private const OID_SENSOR_VALUE = '.1.3.6.1.2.1.99.1.1.1.4';   // entPhySensorValue
    private const OID_SENSOR_STATUS = '.1.3.6.1.2.1.99.1.1.1.5';  // entPhySensorOperStatus
    private const OID_ENT_NAME = '.1.3.6.1.2.1.47.1.1.1.1.7';     // entPhysicalName

    // Juniper JUNIPER-MIB jnxOperatingTable — Junos does not populate
    // HOST-RESOURCES hrProcessorLoad/hrStorage or ENTITY-SENSOR reliably, so
    // CPU/memory/temperature come from the vendor table instead. Each column is
    // indexed per operating component (RE, FPC, PIC…); the device figure is the
    // worst (max non-zero) component. jnxOperatingBuffer is memory utilisation %.
    private const OID_JNX_CPU = '.1.3.6.1.4.1.2636.3.1.13.1.8';    // jnxOperatingCPU, %
    private const OID_JNX_MEM = '.1.3.6.1.4.1.2636.3.1.13.1.11';   // jnxOperatingBuffer, %
    private const OID_JNX_TEMP = '.1.3.6.1.4.1.2636.3.1.13.1.7';   // jnxOperatingTemp, °C
    private const OID_JNX_DESCR = '.1.3.6.1.4.1.2636.3.1.13.1.5';  // jnxOperatingDescr (component name)
    // FortiGate FORTINET-FORTIGATE-MIB scalars.
    private const OID_FORTI_CPU = '.1.3.6.1.4.1.12356.101.4.1.3.0'; // fgSysCpuUsage, %
    private const OID_FORTI_MEM = '.1.3.6.1.4.1.12356.101.4.1.4.0'; // fgSysMemUsage, %

    private const SENSOR_LABELS = [
        3 => 'voltsAC', 4 => 'voltsDC', 5 => 'amperes', 6 => 'watts',
        7 => 'hertz', 8 => 'celsius', 9 => 'percentRH', 10 => 'rpm',
    ];

    private const SENSOR_UNITS = [
        'voltsAC' => 'V', 'voltsDC' => 'V', 'amperes' => 'A', 'watts' => 'W',
        'hertz' => 'Hz', 'celsius' => '°C', 'percentRH' => '%', 'rpm' => 'RPM',
    ];

    /** @param callable(Device, string): string $walker Returns raw snmpwalk stdout for an OID. */
    public function __construct(private $walker)
    {
    }

    /**
     * Read CPU + memory % right now WITHOUT writing to the DB — drives the live health
     * graph. A lightweight subset of poll() (no sensors, no history row): just the
     * vendor-correct CPU/mem OIDs, so it stays fast enough to poll every few seconds.
     *
     * @return array{cpu: ?float, mem: ?float}
     */
    public function probeCpuMem(Device $device): array
    {
        $walk = fn (string $oid) => self::parseWalk(($this->walker)($device, $oid));

        $cpu = self::averageCpu($walk(self::OID_CPU_LOAD));
        $mem = self::memoryPercent($walk(self::OID_STORAGE_TYPE), $walk(self::OID_STORAGE_SIZE), $walk(self::OID_STORAGE_USED));

        if ($device->vendor === 'juniper') {
            $descr = $walk(self::OID_JNX_DESCR);
            $cpu = self::juniperReValue($descr, $walk(self::OID_JNX_CPU)) ?? self::maxValue($walk(self::OID_JNX_CPU)) ?? $cpu;
            $mem = self::juniperReValue($descr, $walk(self::OID_JNX_MEM)) ?? self::maxValue($walk(self::OID_JNX_MEM)) ?? $mem;
        }
        if ($device->vendor === 'fortigate') {
            $cpu = self::maxValue($walk(self::OID_FORTI_CPU)) ?? $cpu;
            $mem = self::maxValue($walk(self::OID_FORTI_MEM)) ?? $mem;
        }

        return ['cpu' => $cpu, 'mem' => $mem];
    }

    public function poll(Device $device): void
    {
        $now = now();
        $walk = fn (string $oid) => self::parseWalk(($this->walker)($device, $oid));

        $cpu = self::averageCpu($walk(self::OID_CPU_LOAD));
        $mem = self::memoryPercent($walk(self::OID_STORAGE_TYPE), $walk(self::OID_STORAGE_SIZE), $walk(self::OID_STORAGE_USED));
        $uptime = self::parseUptime($walk(self::OID_SYS_UPTIME));

        $maxTemp = $this->syncSensors($device, $now,
            $walk(self::OID_SENSOR_TYPE), $walk(self::OID_SENSOR_VALUE),
            $walk(self::OID_SENSOR_PRECISION), $walk(self::OID_SENSOR_STATUS), $walk(self::OID_ENT_NAME));

        // Juniper exposes CPU/memory/temperature only through the vendor
        // jnxOperatingTable. Prefer it; fall back to whatever the generic MIBs
        // yielded (usually nothing on Junos) so a device never regresses.
        if ($device->vendor === 'juniper') {
            // CPU/memory should reflect the Routing Engine, not the busiest
            // component — taking the table max grabbed an FPC/PIC outlier (e.g. 92%
            // while the RE was ~59%). Prefer the RE entry; fall back to the max.
            $descr = $walk(self::OID_JNX_DESCR);
            $cpu = self::juniperReValue($descr, $walk(self::OID_JNX_CPU)) ?? self::maxValue($walk(self::OID_JNX_CPU)) ?? $cpu;
            $mem = self::juniperReValue($descr, $walk(self::OID_JNX_MEM)) ?? self::maxValue($walk(self::OID_JNX_MEM)) ?? $mem;
            $maxTemp = self::maxValue($walk(self::OID_JNX_TEMP)) ?? $maxTemp;
        }

        if ($device->vendor === 'fortigate') {
            $cpu = self::maxValue($walk(self::OID_FORTI_CPU)) ?? $cpu;
            $mem = self::maxValue($walk(self::OID_FORTI_MEM)) ?? $mem;
        }

        // Silver Peak (EdgeConnect) reserves nearly all RAM by design, so "% used"
        // is not a health signal — reclaimable memory (free + buffers + cached) and
        // swap usage are. Compute them from the Linux hrStorage rows.
        [$reclaimableMb, $swapUsedMb] = $device->vendor === 'silverpeak'
            ? self::memoryDetail($walk(self::OID_STORAGE_DESCR), $walk(self::OID_STORAGE_SIZE), $walk(self::OID_STORAGE_USED), $walk(self::OID_STORAGE_UNITS))
            : [null, null];

        DeviceHealth::updateOrCreate(
            ['device_id' => $device->id],
            ['cpu_pct' => $cpu, 'mem_pct' => $mem, 'mem_reclaimable_mb' => $reclaimableMb, 'swap_used_mb' => $swapUsedMb, 'temperature_c' => $maxTemp, 'uptime_seconds' => $uptime, 'polled_at' => $now],
        );

        DeviceHealthHistory::create([
            'device_id' => $device->id,
            'recorded_at' => $now,
            'cpu_pct' => $cpu,
            'mem_pct' => $mem,
            'temperature_c' => $maxTemp,
        ]);
    }

    /**
     * @return ?float Max temperature seen, for the health snapshot.
     */
    private function syncSensors(Device $device, $now, array $types, array $values, array $precisions, array $statuses, array $names): ?float
    {
        $maxTemp = null;
        $seen = [];

        foreach ($types as $index => $typeCode) {
            $label = self::SENSOR_LABELS[self::intCode($typeCode)] ?? null;
            if ($label === null || ! array_key_exists($index, $values)) {
                continue;
            }

            $precision = (int) ($precisions[$index] ?? 0);
            $value = round(((float) $values[$index]) / (10 ** $precision), 2);
            $name = $names[$index] ?? "Sensor {$index}";
            $status = self::intCode($statuses[$index] ?? '1') === 1 ? 'ok' : 'fault';

            DeviceSensor::updateOrCreate(
                ['device_id' => $device->id, 'name' => $name],
                ['sensor_type' => $label, 'value' => $value, 'unit' => self::SENSOR_UNITS[$label] ?? null, 'status' => $status, 'last_seen_at' => $now],
            );
            $seen[] = $name;

            if ($label === 'celsius') {
                $maxTemp = $maxTemp === null ? $value : max($maxTemp, $value);
            }
        }

        // Drop sensors that vanished from the device.
        DeviceSensor::where('device_id', $device->id)->whereNotIn('name', $seen ?: ['__none__'])->delete();

        return $maxTemp;
    }

    /** Numeric SNMP enum code from either "8" or MIB-resolved "celsius(8)". */
    private static function intCode(string $value): int
    {
        return preg_match('/\((\d+)\)/', $value, $m) ? (int) $m[1] : (int) $value;
    }

    /** @param array<int, string> $loads */
    public static function averageCpu(array $loads): ?float
    {
        if (empty($loads)) {
            return null;
        }

        $ints = array_map('intval', $loads);

        return round(array_sum($ints) / count($ints), 2);
    }

    /** RAM utilisation % from the hrStorage table, or null when no RAM row is present. */
    public static function memoryPercent(array $types, array $sizes, array $used): ?float
    {
        foreach ($types as $index => $type) {
            $isRam = str_contains($type, 'hrStorageRam') || str_contains($type, '25.2.1.2');
            $size = (int) ($sizes[$index] ?? 0);

            if ($isRam && $size > 0) {
                return round((int) ($used[$index] ?? 0) / $size * 100, 2);
            }
        }

        return null;
    }

    /**
     * Reclaimable memory (free + buffers + cached) and swap used, in MB, from the
     * Linux hrStorage rows — the memory signals that actually matter on an
     * appliance that intentionally uses all its RAM. Rows are matched by their
     * hrStorageDescr ("Physical memory", "Memory buffers", "Cached memory",
     * "Swap space"), each scaled by its own allocation-unit size.
     *
     * @return array{0: ?int, 1: ?int} [reclaimable_mb, swap_used_mb]
     */
    public static function memoryDetail(array $descrs, array $sizes, array $used, array $units): array
    {
        $freeBytes = null;
        $buffersBytes = 0;
        $cachedBytes = 0;
        $swapBytes = null;

        foreach ($descrs as $index => $descr) {
            $unit = max(1, (int) ($units[$index] ?? 1024));
            $sizeBytes = (int) ($sizes[$index] ?? 0) * $unit;
            $usedBytes = (int) ($used[$index] ?? 0) * $unit;
            $d = strtolower(trim($descr));

            if ($d === 'physical memory') {
                $freeBytes = max(0, $sizeBytes - $usedBytes);
            } elseif (str_contains($d, 'buffer')) {
                $buffersBytes = $usedBytes;
            } elseif (str_contains($d, 'cached')) {
                $cachedBytes = $usedBytes;
            } elseif (str_contains($d, 'swap')) {
                $swapBytes = $usedBytes;
            }
        }

        // No physical-memory row → not a Linux hrStorage device; report nothing.
        if ($freeBytes === null) {
            return [null, null];
        }

        $reclaimableMb = (int) round(($freeBytes + $buffersBytes + $cachedBytes) / 1048576);
        $swapUsedMb = $swapBytes === null ? null : (int) round($swapBytes / 1048576);

        return [$reclaimableMb, $swapUsedMb];
    }

    /**
     * Worst (max) non-zero value in a jnxOperatingTable column. A component that
     * doesn't apply (e.g. a slot with no CPU) reports 0, so zeros are ignored;
     * returns null when the whole column is absent or all-zero.
     *
     * @param  array<int, string>  $walk
     */
    public static function maxValue(array $walk): ?float
    {
        $values = array_filter(array_map('floatval', $walk), fn ($v) => $v > 0);

        return $values === [] ? null : (float) max($values);
    }

    /**
     * The value of the Routing-Engine row, correlated by shared table index with
     * jnxOperatingDescr. Returns null if no RE row is found (caller falls back).
     *
     * @param  array<int, string>  $descr
     * @param  array<int, string>  $value
     */
    public static function juniperReValue(array $descr, array $value): ?float
    {
        foreach ($descr as $index => $name) {
            if (preg_match('/routing engine|\bRE\d?\b/i', (string) $name) && isset($value[$index])) {
                $v = (float) $value[$index];
                if ($v > 0) {
                    return $v;
                }
            }
        }

        return null;
    }

    /** sysUpTime is TimeTicks (1/100s). Handles both "(N) ..." and bare-integer forms. */
    public static function parseUptime(array $walk): ?int
    {
        $raw = $walk[0] ?? (reset($walk) ?: '');

        if (preg_match('/\((\d+)\)/', (string) $raw, $m)) {
            return intdiv((int) $m[1], 100);
        }

        return is_numeric(trim((string) $raw)) ? intdiv((int) $raw, 100) : null;
    }

    /**
     * Parse snmpwalk output into index => value. Tolerant of MIB-resolved and
     * numeric output and of any SNMP type prefix (Gauge32, Timeticks, OID, ...).
     *
     * @return array<int, string>
     */
    public static function parseWalk(string $output): array
    {
        $values = [];

        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/\.(\d+)\s*=\s*(.+)$/', trim($line), $m)) {
                continue;
            }

            $value = preg_replace('/^[A-Za-z0-9-]+:\s*/', '', trim($m[2]));

            if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
                $value = substr($value, 1, -1);
            }

            $values[(int) $m[1]] = $value;
        }

        return $values;
    }
}
