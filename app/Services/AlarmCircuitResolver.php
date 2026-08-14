<?php

namespace App\Services;

use App\Models\Circuit;
use Illuminate\Support\Collection;

/**
 * Maps an EdgeConnect device alarm to the ISP circuit it rides.
 *
 * A site with ISP redundancy has one circuit per WAN uplink; when a provider's
 * link degrades, its next-hop, IP-SLA, and tunnels all alarm. Grouping those
 * under one circuit lets the NOC record a single ISP ticket + dispatch per
 * provider. The matching vocabulary mirrors EdgeConnectAlarmPoller's mute logic
 * (gateway IP, wanN token, tunnel local-WAN label) — that code proves these
 * signals identify a circuit; this resolves the inverse for grouping.
 *
 * Site-wide rollups ("Tunnel", "System"/NTP) and unmatched alarms return null so
 * they fall into a site-level bucket rather than a wrong provider.
 */
class AlarmCircuitResolver
{
    /** The SNMP "source" segment of an alarm_id "ec:<typeId>:<source>". */
    public static function sourceOf(string $alarmId): string
    {
        if (! str_starts_with($alarmId, 'ec:')) {
            return '';
        }
        $rest = substr($alarmId, 3);          // "196625:gw:1.2.3.4"
        $pos = strpos($rest, ':');

        return $pos === false ? '' : substr($rest, $pos + 1); // "gw:1.2.3.4"
    }

    /**
     * The circuit this alarm belongs to, or null (site-wide / unmatched).
     *
     * @param  Collection<int,Circuit>  $circuits  the site's circuits
     * @param  Collection<int,\App\Models\DeviceNextHop>  $nextHops  the device's next-hops (ip→interface)
     */
    public function resolve(string $alarmId, string $description, Collection $circuits, Collection $nextHops): ?Circuit
    {
        if ($circuits->isEmpty()) {
            return null;
        }

        $source = self::sourceOf($alarmId);
        $srcLower = strtolower(trim($source));
        $hay = strtolower($description.' '.$source);

        // Site-wide / appliance alarms never belong to a single circuit.
        if ($source === '' || $srcLower === 'tunnel' || $srcLower === 'system') {
            return null;
        }

        // 1) Next-hop "gw:<ip>" — the circuit that owns that gateway IP, directly or
        //    via the device's next-hop table (ip → interface → circuit.wan_interface).
        if (str_starts_with($srcLower, 'gw:')) {
            $ipLower = strtolower(trim(substr($source, 3)));
            foreach ($circuits as $c) {
                if (strtolower(trim((string) $c->gateway_ip)) === $ipLower
                    || strtolower(trim((string) $c->monitored_ip)) === $ipLower) {
                    return $c;
                }
            }
            $nh = $nextHops->first(fn ($n) => strtolower(trim((string) $n->ip_address)) === $ipLower);
            $iface = strtolower(trim((string) optional($nh)->interface));
            if ($iface !== '') {
                return $circuits->first(fn ($c) => strtolower(trim((string) $c->wan_interface)) === $iface);
            }

            return null;
        }

        // 2) Tunnel "to_<remote>_<localWan>-<remoteWan>" — its LOCAL egress WAN label.
        //    (Remote/hub-end resolution is deferred; those alarms land on the hub.)
        if (str_starts_with($srcLower, 'to_')) {
            $localWan = $this->tunnelLocalWan($source);
            if ($localWan !== '') {
                return $circuits->first(fn ($c) => strtolower(trim((string) $c->wan_interface)) === $localWan
                    || strtolower(trim((string) $c->circuit_type)) === $localWan);
            }

            return null;
        }

        // 3) IP-SLA / WAN alarms — a "wanN" token in the text → circuit by wan_interface.
        foreach ($circuits as $c) {
            $w = strtolower(trim((string) $c->wan_interface));
            if ($w !== '' && preg_match('/\b'.preg_quote($w, '/').'\b/', $hay)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Is this a LOCAL-transport alarm — i.e. the appliance's own WAN uplink is failing,
     * so the circuit riding it is genuinely degraded? True for a gateway-down (`gw:`) or
     * an IP-SLA / WAN (`wanN`) alarm. Deliberately FALSE for per-tunnel `to_*` (a single
     * tunnel can be down because the REMOTE hub is down, not this circuit) and for the
     * site-wide Tunnel/System rollups — folding those in would raise false positives.
     */
    public static function isLocalTransport(string $alarmId, string $description): bool
    {
        $src = strtolower(trim(self::sourceOf($alarmId)));
        if ($src === '' || $src === 'tunnel' || $src === 'system' || str_starts_with($src, 'to_')) {
            return false;
        }
        if (str_starts_with($src, 'gw:')) {
            return true;
        }

        return (bool) preg_match('/\bwan\d\b/', strtolower($description.' '.$src));
    }

    /** A short, human reason for the transport-degraded state, by alarm kind. */
    public static function transportReason(string $alarmId): string
    {
        $src = strtolower(trim(self::sourceOf($alarmId)));

        return str_starts_with($src, 'gw:') ? 'Gateway unreachable' : 'IP-SLA down on WAN uplink';
    }

    /** "to_FL0018-SC24_DIA1-DIA1" → "dia1" — first token of the trailing "_"-segment. */
    private function tunnelLocalWan(string $source): string
    {
        $last = ltrim((string) strrchr($source, '_'), '_'); // "DIA1-DIA1"
        $dash = strpos($last, '-');

        return strtolower($dash === false ? $last : substr($last, 0, $dash));
    }
}
