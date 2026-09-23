<?php

namespace App\Support;

/**
 * Reads the appliance's SD-WAN tunnel alarms.
 *
 * Two different events arrive against the same alarm shape:
 *
 *   ec:65537:to_AZURE-PRI_Broadband1-DIA1   Tunnel state is Down
 *   ec:327686:to_AZURE-PRI_Managment        latency exceeds the threshold of 1000ms
 *
 * The id cannot tell them apart — only the description can. Treating the second as
 * an outage is what made every open alarm on the reference fleet read critical, and
 * a real outage would have been indistinguishable in that list.
 *
 * The name also carries the peer, in the same to_<peer>_<class> form the SSH
 * 'show tunnels' output uses, so an SNMP alarm can be attributed to the hub whose
 * per-hub row the operator is looking at.
 */
class TunnelAlarm
{
    /** A degraded-but-up threshold breach, not a tunnel that stopped passing traffic. */
    public static function isQuality(?string $description): bool
    {
        return (bool) preg_match('/\b(latency|jitter|loss|threshold|exceeds)\b/i', (string) $description);
    }

    /** Any tunnel alarm: the per-tunnel form or the vague fleet-wide rollup. */
    public static function isTunnel(string $alarmId): bool
    {
        return (bool) preg_match('/^ec:\d+:(Tunnel$|to_)/i', $alarmId)
            || str_contains(strtolower($alarmId), 'tunnel_down');
    }

    /** The rollup alarm — "many tunnels are down", with no per-tunnel detail. */
    public static function isRollup(string $alarmId): bool
    {
        return (bool) preg_match('/^ec:\d+:Tunnel$/i', $alarmId)
            || str_contains(strtolower($alarmId), 'tunnel_down');
    }

    /** 'to_AZURE-PRI_Broadband1-DIA1' => 'AZURE-PRI'. */
    public static function peerOf(string $tunnelName): ?string
    {
        return preg_match('/^to_(.+)_[^_]+$/', $tunnelName, $m) ? $m[1] : null;
    }

    /**
     * The hub a tunnel terminates on: the peer with its redundancy suffix dropped,
     * so AZURE-PRI and AZURE-SEC roll up to the one AZURE row the operator sees.
     */
    public static function hubOf(string $tunnelName): ?string
    {
        $peer = self::peerOf($tunnelName);

        return $peer === null ? null : preg_replace('/-(PRI|SEC|PRIMARY|SECONDARY)$/i', '', $peer);
    }

    /**
     * Hubs with tunnels the appliance currently reports DOWN over SNMP.
     *
     * Quality breaches are excluded — they are open constantly and would paint every
     * hub red. Counted per distinct tunnel, since one hub commonly carries several.
     *
     * @param  iterable<object>  $alarms  Alarm rows with alarm_id / description.
     * @return array<string, int> hub => number of its tunnels down
     */
    public static function downHubs(iterable $alarms): array
    {
        $seen = [];

        foreach ($alarms as $alarm) {
            $id = (string) $alarm->alarm_id;

            if (! preg_match('/^ec:\d+:(to_.+)$/i', $id, $m) || self::isQuality($alarm->description ?? null)) {
                continue;
            }

            // By tunnel name, not by row: the same tunnel can carry more than one open
            // alarm and must still count as one tunnel down.
            $seen[self::hubOf($m[1]) ?? $m[1]][$m[1]] = true;
        }

        return array_map('count', $seen);
    }

    /** True when the appliance has the detail-free rollup alarm open. */
    public static function hasRollup(iterable $alarms): bool
    {
        foreach ($alarms as $alarm) {
            if (self::isRollup((string) $alarm->alarm_id) && ! self::isQuality($alarm->description ?? null)) {
                return true;
            }
        }

        return false;
    }
}
