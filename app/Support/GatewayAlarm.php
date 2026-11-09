<?php

namespace App\Support;

use App\Services\AlarmCircuitResolver;

/**
 * The appliance's own report that a WAN gateway is unreachable.
 *
 * The same failure reaches this app down two paths. SNMP raises an EdgeConnect alarm
 * whose source names the gateway — `ec:196625:gw:173.8.45.6`. SSH reads
 * `show system nexthops` and opens a NextHopAlert on the DeviceNextHop row holding
 * that same IP. Both are true, both are about one WAN uplink, and until now both were
 * emitted as separate rows: one worded "Gateway unreachable", the other "Next-hop
 * unreachable", each with its own severity — so #075's wan0 showed up twice, once
 * critical and once warning, and an operator had to work out that it was one fault.
 *
 * The house rule settles which survives: SNMP is the authoritative signal and SSH only
 * confirms and adds detail. The 90-second SNMP loop sees a gateway drop long before a
 * sequential SSH sweep of ~140 appliances comes round, so the SNMP alarm is the one
 * that stays — carrying the interface name SSH knows and SNMP does not.
 *
 * NOT a suppression of the underlying fact: nothing is cleared, nothing is hidden. One
 * fault is shown once.
 */
final class GatewayAlarm
{
    /**
     * Gateway IPs an open SNMP alarm already reports down, per device.
     *
     * @param  iterable<\App\Models\DeviceAlarm>  $openAlarms
     * @return array<int, array<string, true>> device id => lowercased ip => true
     */
    public static function ipsByDevice(iterable $openAlarms): array
    {
        $map = [];
        foreach ($openAlarms as $alarm) {
            $ip = self::gatewayIp((string) $alarm->alarm_id);
            if ($ip === null || $alarm->device_id === null) {
                continue;
            }
            $map[$alarm->device_id][$ip] = true;
        }

        return $map;
    }

    /**
     * The gateway IP an alarm names, or null when it is not a gateway alarm.
     *
     * Deliberately exact: only the `gw:<ip>` source counts. An IP-SLA or WAN-interface
     * alarm is a DIFFERENT fault on the same uplink (the next-hop ARPs but the path to
     * the internet is dead), and folding those together would hide one behind the other.
     */
    public static function gatewayIp(string $alarmId): ?string
    {
        $source = AlarmCircuitResolver::sourceOf($alarmId);
        if (! str_starts_with(strtolower(trim($source)), 'gw:')) {
            return null;
        }

        $ip = strtolower(trim(substr(trim($source), 3)));

        return $ip === '' ? null : $ip;
    }

    /**
     * Is this next-hop already reported by an SNMP gateway alarm on the same device?
     *
     * @param  array<int, array<string, true>>  $ipsByDevice
     */
    public static function covers(array $ipsByDevice, ?int $deviceId, ?string $ip): bool
    {
        if ($deviceId === null || $ip === null || trim($ip) === '') {
            return false;
        }

        return isset($ipsByDevice[$deviceId][strtolower(trim($ip))]);
    }
}
