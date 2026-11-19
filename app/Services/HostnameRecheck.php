<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAlarm;
use Illuminate\Support\Facades\Log;

/**
 * Lets the network, rather than a clock, decide when a hostname is worth re-reading.
 *
 * HostnamePoller re-walks sysName every 6 hours, which is the right cadence for a
 * field that almost never changes. The cost shows up on the day it does: on 18
 * September an EdgeConnect at SC125 was renamed on the console, its peers began
 * naming the tunnels "to_SC125-ECB01_*" within ten minutes, and Nodus went on
 * believing the box was "GA0008-SC125_SDW" for the rest of the refresh window.
 * TunnelCorrelation could not resolve the new token to a site, so every tunnel
 * symptom for that branch stayed on the HQ hubs and read as an HQ fault.
 *
 * The peers had already told us. A tunnel alarm names its remote end, so a
 * "to_<token>" that matches no appliance we know is direct evidence that some
 * appliance is answering to a name we have not read yet. That is the trigger: find
 * the appliance the peers have STOPPED naming that shares the unresolved token's
 * service-centre number, expire its hostname timestamp, and the next health cycle
 * re-walks it — minutes instead of hours.
 *
 * Deliberately narrow. It only acts when an unresolved token actually exists, it
 * only ever clears a timestamp (never writes a name — adopting a hostname stays an
 * operator decision, see DeviceHostnameController), and it is capped per sweep so a
 * fleet-wide naming change cannot turn into 300 extra SNMP walks at once.
 */
class HostnameRecheck
{
    /** A rename is a handful of boxes, not the fleet. Bound the fallout of a bad guess. */
    public const MAX_PER_SWEEP = 12;

    /**
     * @return list<int> ids of the devices queued for an immediate hostname re-read
     */
    public function run(): array
    {
        $alarms = DeviceAlarm::whereNull('cleared_at')
            ->where('alarm_id', 'like', '%:to%')
            ->pluck('alarm_id');

        // What the peers are calling their remote ends right now. Keyed by the
        // canonical token (how they are compared) but keeping the raw one, because
        // canonicalising strips the zero-padding that makes a site code legible as a
        // site code — "GA0008" arrives here as "ga8".
        $observed = [];
        foreach ($alarms as $alarmId) {
            $token = TunnelCorrelation::remoteToken($alarmId);
            if ($token !== null) {
                $observed[TunnelCorrelation::canonicalToken($token)] = $token;
            }
        }

        if ($observed === []) {
            return [];
        }

        $edges = Device::where('role', 'edgeconnect')
            ->with('site:id,site_number')
            ->get(['id', 'site_id', 'name', 'snmp_hostname', 'previous_name', 'hostname_checked_at']);

        // Every name we already know an appliance by — the same aliases
        // TunnelCorrelation resolves against, so "unresolved" means the same thing
        // in both places.
        $known = [];
        foreach ($edges as $edge) {
            foreach (self::aliases($edge) as $alias) {
                $known[$alias] = true;
            }
        }

        $unresolved = array_diff_key($observed, $known);
        if ($unresolved === []) {
            return [];    // every remote end accounted for; nothing was renamed
        }

        // Which appliance is it? The unresolved token still carries the service-centre
        // number — "sc125-ecb1" and the record's "ga8-sc125" share the run 125, and so
        // does site #125. Matching on that keeps this to the box that actually moved:
        // an earlier cut queued "every appliance the peers stopped naming", which on a
        // quiet fleet is nearly all of them, hubs included.
        $numbers = self::significantNumbers(implode(' ', $unresolved));

        // Devices with an already-stale timestamp are skipped: HostnamePoller will walk
        // those on its own next pass, so expiring them again buys nothing.
        $stale = now()->subHours(HostnamePoller::REFRESH_HOURS);

        $candidates = $edges
            ->filter(fn (Device $d) => $d->hostname_checked_at !== null && $d->hostname_checked_at->gt($stale))
            ->filter(fn (Device $d) => ! self::namedByPeers($d, $observed))
            ->filter(fn (Device $d) => self::sharesNumber($d, $numbers))
            ->take(self::MAX_PER_SWEEP);

        if ($candidates->isEmpty()) {
            return [];
        }

        $ids = $candidates->pluck('id')->all();

        Device::whereIn('id', $ids)->update(['hostname_checked_at' => null]);

        Log::info('Hostname re-read queued from tunnel evidence', [
            'unresolved_peer_tokens' => array_keys($unresolved),
            'device_ids' => $ids,
        ]);

        return $ids;
    }

    /**
     * True when a digit-run from the unresolved token also appears in this device's
     * own name or its site number — the thread that survives a rename, because the
     * service-centre number is what both the old and new names are built around.
     */
    private static function sharesNumber(Device $device, array $numbers): bool
    {
        if ($numbers === []) {
            return false;
        }

        $haystack = self::significantNumbers((string) $device->name);
        $siteNumber = ltrim((string) $device->site?->site_number, '0');
        if ($siteNumber !== '') {
            $haystack[$siteNumber] = true;
        }

        return array_intersect_key($numbers, $haystack) !== [];
    }

    /**
     * The digit-runs in a name that actually identify a place, keyed by value with
     * zero-padding removed so SC125 and sc0125 agree.
     *
     * Runs shorter than three characters are ignored: the site code and the
     * service-centre number are written padded (GA0008, SC125, #893), while a
     * trailing unit index is not. Counting those matched everything — "SC125-ECB01"
     * shares its "01" with "FL0001-HQ-ECH01", which would have sent the HQ hub for
     * an SNMP walk every time a branch was renamed.
     *
     * @return array<string, true>
     */
    private static function significantNumbers(string $text): array
    {
        preg_match_all('/\d+/', $text, $m);

        $out = [];
        foreach ($m[0] as $run) {
            if (strlen($run) >= 3) {
                $out[ltrim($run, '0') ?: '0'] = true;
            }
        }

        return $out;
    }

    /** True when any name we hold for this device is still being named by a peer. */
    private static function namedByPeers(Device $device, array $observed): bool
    {
        foreach (self::aliases($device) as $alias) {
            if (isset($observed[$alias])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical tokens for every name this device answers, or has answered, to.
     *
     * @return list<string>
     */
    private static function aliases(Device $device): array
    {
        $out = [];
        foreach ([$device->name, $device->snmp_hostname, $device->previous_name] as $alias) {
            $token = TunnelCorrelation::canonicalToken(TunnelCorrelation::labelToken((string) $alias));
            if ($token !== '') {
                $out[] = $token;
            }
        }

        return array_values(array_unique($out));
    }
}
