<?php

namespace App\Support;

use App\Models\Device;
use App\Models\MaintenanceWindow;

/**
 * Which devices are being worked on right now.
 *
 * "A device under maintenance" was answered independently by whichever screen
 * happened to care. Circuits had their own handling from the start, notifications
 * had theirs, availability reporting had a third — and the dashboard, the alarm
 * list, the topology and the wallboard had none at all, which is how a switch under
 * a thirty-day window went on showing a red DOWN alarm on every one of them.
 *
 * One definition, asked once per request. The same lesson as Measurement: a rule
 * restated per view is a rule that will disagree with itself.
 *
 * WHAT THIS IS NOT: a mute. A device being worked on is genuinely unreachable, and
 * dropping its alarm would be the false-healthy failure this codebase keeps being
 * dug out of. Callers mark it, count it apart, and show it without the red — they
 * do not delete it.
 */
final class Maintenance
{
    /**
     * Memo key. Held on the CONTAINER, not in a static property: a static would
     * outlive the request inside a long-running worker (and leak between tests),
     * which for a lookup that changes the moment somebody opens a window is exactly
     * the kind of stale answer this class exists to prevent.
     */
    private const MEMO = 'maintenance.device_ids';

    /**
     * Device ids covered by an active window — scoped to the device, to its site,
     * or to the whole fleet. All three cover the device.
     *
     * Memoised for the life of the request: a dashboard build walks thousands of
     * alarms and must not re-ask per row.
     *
     * @return array<int, bool>
     */
    public static function deviceIds(): array
    {
        if (app()->bound(self::MEMO)) {
            return app()->make(self::MEMO);
        }

        return app()->instance(self::MEMO, self::compute());
    }

    /** @return array<int, bool> */
    private static function compute(): array
    {
        $windows = MaintenanceWindow::active()->get(['site_id', 'device_id']);
        if ($windows->isEmpty()) {
            return [];
        }

        // A window with neither a site nor a device covers everything.
        if ($windows->contains(fn ($w) => ! $w->site_id && ! $w->device_id)) {
            return Device::pluck('id')->mapWithKeys(fn ($id) => [$id => true])->all();
        }

        $ids = $windows->pluck('device_id')->filter();
        $siteIds = $windows->pluck('site_id')->filter();
        if ($siteIds->isNotEmpty()) {
            $ids = $ids->concat(Device::whereIn('site_id', $siteIds)->pluck('id'));
        }

        return $ids->unique()->mapWithKeys(fn ($id) => [$id => true])->all();
    }

    public static function covers(?int $deviceId): bool
    {
        return $deviceId !== null && isset(self::deviceIds()[$deviceId]);
    }

    /** A window opened or closed mid-process; forget what was answered before it. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }
}
