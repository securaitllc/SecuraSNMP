<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use Illuminate\Support\Collection;

/**
 * Cross-site tunnel correlation for the SD-WAN overlay.
 *
 * A hub holds a tunnel to every spoke, so when a spoke's transport fails the HUB
 * alarms too ("to_<spoke>" tunnel down) — and a hub outage erupts as ~130 tunnel
 * alarms across the fleet. Both are symptoms of ONE end's real problem.
 *
 * The rule: a tunnel alarm "to_<remote>" is a symptom of the REMOTE end — so when
 * that remote site has a genuine LOCAL-transport problem (its edge unreachable, or
 * its gateway / IP-SLA / circuit down), the tunnel alarm is suppressed from wherever
 * it landed and rolled under the remote site's incident. This works in BOTH
 * directions with no special-casing:
 *   - hub "to_<spoke>" alarms suppress when the spoke's transport is down;
 *   - every spoke's "to_<hub>" alarm suppresses when the hub's transport is down
 *     (one hub incident affecting N spokes, instead of N scattered tunnel alarms).
 *
 * The tunnel ROLLUP (":Tunnel") and the per-tunnel symptom are never used to judge
 * health — only a site's own local transport is, so this can't chase its own tail.
 */
class TunnelCorrelation
{
    /** @return array{suppressed_alarm_ids: list<int>, incidents: list<array<string,mixed>>} */
    public function analyze(): array
    {
        // Every appliance's label token → its site, so a "to_<token>" resolves to a site.
        $edges = Device::where('role', 'edgeconnect')->get(['id', 'site_id', 'name']);
        $tokenToSite = [];
        foreach ($edges as $e) {
            $t = self::labelToken($e->name);
            if ($t !== '' && $e->site_id !== null) {
                $tokenToSite[$t] = $e->site_id;
            }
        }

        $unhealthy = $this->unhealthySites();      // site_id => reason
        if ($unhealthy === [] || $tokenToSite === []) {
            return ['suppressed_alarm_ids' => [], 'incidents' => []];
        }

        $siteNames = \App\Models\Site::whereIn('id', array_keys($unhealthy))->pluck('name', 'id');
        $deviceById = $edges->keyBy('id');

        // Active per-tunnel alarms ("ec:<t>:to_<remote>_<localWan>-<remoteWan>").
        // ":to" narrows it in SQL; remoteToken() below confirms the exact "to_" shape
        // (a bound "\_" escape is unreliable across SQLite/MySQL, so we filter in PHP).
        $tunnelAlarms = DeviceAlarm::whereNull('cleared_at')
            ->where('alarm_id', 'like', '%:to%')
            ->get(['id', 'device_id', 'alarm_id']);

        $bySite = [];        // rootSiteId => [alarm ids]
        $peersBySite = [];   // rootSiteId => set of device names carrying the symptom
        foreach ($tunnelAlarms as $a) {
            $token = self::remoteToken($a->alarm_id);
            $remoteSite = $token !== null ? ($tokenToSite[$token] ?? null) : null;
            if ($remoteSite === null || ! isset($unhealthy[$remoteSite])) {
                continue; // remote end healthy or unknown → this is a real local alarm, keep it
            }
            $bySite[$remoteSite][] = $a->id;
            $carrier = optional($deviceById->get($a->device_id))->name;
            if ($carrier) {
                $peersBySite[$remoteSite][$carrier] = true;
            }
        }

        $suppressed = [];
        $incidents = [];
        foreach ($bySite as $siteId => $ids) {
            $suppressed = array_merge($suppressed, $ids);
            $peers = array_keys($peersBySite[$siteId] ?? []);
            $incidents[] = [
                'kind' => 'tunnel',
                'site_id' => $siteId,
                'site_name' => $siteNames[$siteId] ?? null,
                'root_device_name' => $siteNames[$siteId] ?? "site {$siteId}",
                'root_role' => 'sd-wan',
                'reason' => $unhealthy[$siteId],
                'affected' => ['tunnels' => count($ids)],
                'affected_total' => count($ids),
                'suppressed_count' => count($ids),
                'suppressed_alarm_ids' => $ids,
                'suppressed_device_names' => $peers,
            ];
        }

        return ['suppressed_alarm_ids' => array_values(array_unique($suppressed)), 'incidents' => $incidents];
    }

    /**
     * Sites with a genuine LOCAL-transport failure — the root a tunnel symptom rolls up
     * to. Edge unreachable, an active gateway/IP-SLA alarm, or a down circuit. NOT the
     * tunnel rollup itself (that is the symptom, and would make this circular).
     *
     * @return array<int,string>  site_id => short reason
     */
    private function unhealthySites(): array
    {
        $out = [];

        // Edge appliance unreachable.
        DeviceAlarm::whereNull('cleared_at')->where('alarm_id', 'device-unreachable')
            ->join('devices', 'devices.id', '=', 'device_alarms.device_id')
            ->where('devices.role', 'edgeconnect')
            ->pluck('devices.site_id')->filter()
            ->each(function ($s) use (&$out) { $out[$s] = 'edge unreachable'; });

        // Local-transport alarm (gateway / IP-SLA) on the site's edge.
        DeviceAlarm::whereNull('cleared_at')
            ->join('devices', 'devices.id', '=', 'device_alarms.device_id')
            ->where('devices.role', 'edgeconnect')
            ->get(['device_alarms.alarm_id', 'device_alarms.description', 'devices.site_id'])
            ->each(function ($a) use (&$out) {
                if (! isset($out[$a->site_id]) && AlarmCircuitResolver::isLocalTransport($a->alarm_id, (string) $a->description)) {
                    $out[$a->site_id] = 'gateway / IP-SLA down';
                }
            });

        // A circuit at the site is down.
        Circuit::where('status', 'down')->whereNotNull('site_id')->pluck('site_id')
            ->each(function ($s) use (&$out) { $out[$s] = $out[$s] ?? 'circuit down'; });

        return $out;
    }

    /** "FL0092-HCF_SDW" → "fl0092-hcf" — the token that appears in tunnel labels. */
    public static function labelToken(?string $deviceName): string
    {
        $n = strtolower(trim((string) $deviceName));

        return trim((string) preg_replace('/[_\- ]*sdw\d*$/', '', $n));
    }

    /** "ec:65537:to_FL0034-SC055_DIA1-DIA1" → "fl0034-sc055" (the remote appliance token). */
    public static function remoteToken(string $alarmId): ?string
    {
        $src = AlarmCircuitResolver::sourceOf($alarmId); // "to_FL0034-SC055_DIA1-DIA1"
        if (! str_starts_with(strtolower($src), 'to_')) {
            return null;
        }
        $afterTo = substr($src, 3);                       // "FL0034-SC055_DIA1-DIA1"
        $lastUnderscore = strrpos($afterTo, '_');
        $token = $lastUnderscore === false ? $afterTo : substr($afterTo, 0, $lastUnderscore);
        $token = strtolower(trim($token));

        return $token === '' ? null : $token;
    }
}
