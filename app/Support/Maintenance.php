<?php

namespace App\Support;

use App\Models\Device;
use App\Models\MaintenanceWindow;

/**
 * Which devices and sites are being worked on right now.
 *
 * "A device under maintenance" was answered independently by whichever screen
 * happened to care. Circuits had their own handling from the start, notifications
 * had theirs, availability reporting had a third, the anomaly detector had a fourth —
 * and the dashboard, the alarm list, the topology and the wallboard had none at all,
 * which is how a switch under a thirty-day window went on showing a red DOWN alarm on
 * every one of them.
 *
 * One definition. The same lesson as Measurement: a rule restated per view is a rule
 * that will disagree with itself.
 *
 * WHAT THIS IS NOT: a mute. A device being worked on is genuinely unreachable, and
 * dropping its alarm would be the false-healthy failure this codebase keeps being
 * dug out of. Callers mark it, count it apart, and show it without the red — they
 * do not delete it.
 */
final class Maintenance
{
    /**
     * How long an answer may be reused.
     *
     * The memo has to serve two very different callers. A web request walks thousands
     * of alarms and must not re-query per row. A poller is ONE process that stays up
     * for weeks, so anything cached "for the life of the process" is cached forever:
     * a window opened after the poller booted would never be seen, and one that closed
     * would suppress findings indefinitely. That is precisely how the anomaly detector
     * was behaving.
     *
     * So the memo expires by wall clock rather than by scope. Short enough that a
     * window somebody just opened takes effect while they are still looking at the
     * screen; long enough that no single request re-queries.
     */
    private const TTL_SECONDS = 30;

    /**
     * Memo slot. Held on the CONTAINER, never in a static property: a static also
     * survives between tests, and a lookup that changes the moment somebody opens a
     * window is the last thing that should carry state across a boundary.
     */
    private const MEMO = 'maintenance.memo';

    /**
     * Device ids covered by an active window — scoped to the device, to its site,
     * or to the whole fleet. All three cover the device.
     *
     * @return array<int, bool>
     */
    public static function deviceIds(): array
    {
        return self::memo()['devices'];
    }

    /**
     * Site ids with a window of their own, plus true for a fleet-wide window.
     *
     * Needed by callers that have a site but no device — a circuit anomaly, for
     * instance. A device-scoped window does NOT make its site "under maintenance":
     * one switch being replaced says nothing about the rest of the building.
     *
     * @return array{global: bool, sites: array<int, bool>}
     */
    public static function siteIds(): array
    {
        $memo = self::memo();

        return ['global' => $memo['global'], 'sites' => $memo['sites']];
    }

    public static function covers(?int $deviceId): bool
    {
        if ($deviceId === null) {
            return false;
        }

        $memo = self::memo();

        // The fleet-wide flag is checked first, not left to the materialised device
        // list: coversSite() answers a global window from the flag, and the list is
        // only as fresh as the memo — a device discovered inside the TTL would
        // otherwise read as not-in-maintenance while the whole fleet is under a window.
        return $memo['global'] || isset($memo['devices'][$deviceId]);
    }

    public static function coversSite(?int $siteId): bool
    {
        $memo = self::memo();

        return $memo['global'] || ($siteId !== null && isset($memo['sites'][$siteId]));
    }

    /** A window opened or closed and the caller cannot wait out the TTL. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * @return array{devices: array<int, bool>, sites: array<int, bool>, global: bool, at: int}
     */
    private static function memo(): array
    {
        if (app()->bound(self::MEMO)) {
            $memo = app()->make(self::MEMO);
            // now() rather than time(): the clock the rest of the app reads, and the
            // one a test can move.
            if (now()->getTimestamp() - $memo['at'] < self::TTL_SECONDS) {
                return $memo;
            }
        }

        return app()->instance(self::MEMO, self::compute());
    }

    /**
     * @return array{devices: array<int, bool>, sites: array<int, bool>, global: bool, at: int}
     */
    private static function compute(): array
    {
        $at = now()->getTimestamp();
        $windows = MaintenanceWindow::active()->get(['site_id', 'device_id']);
        if ($windows->isEmpty()) {
            return ['devices' => [], 'sites' => [], 'global' => false, 'at' => $at];
        }

        // A window with neither a site nor a device covers everything.
        if ($windows->contains(fn ($w) => ! $w->site_id && ! $w->device_id)) {
            return [
                'devices' => Device::pluck('id')->mapWithKeys(fn ($id) => [$id => true])->all(),
                'sites' => [],
                'global' => true,
                'at' => $at,
            ];
        }

        $ids = $windows->pluck('device_id')->filter();
        $siteIds = $windows->pluck('site_id')->filter();
        if ($siteIds->isNotEmpty()) {
            // Resolved from the CURRENT device table, not a map captured at boot — a
            // device added or moved after the process started still belongs to its site.
            $ids = $ids->concat(Device::whereIn('site_id', $siteIds)->pluck('id'));
        }

        return [
            'devices' => $ids->unique()->mapWithKeys(fn ($id) => [$id => true])->all(),
            'sites' => $siteIds->unique()->mapWithKeys(fn ($id) => [$id => true])->all(),
            'global' => false,
            'at' => $at,
        ];
    }
}
