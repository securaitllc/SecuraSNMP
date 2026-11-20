<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DevicePollHealth;
use App\Models\MaintenanceWindow;
use Illuminate\Support\Facades\Log;

/**
 * Records whether each poller can still reach each device, and alarms when one
 * silently stops being able to.
 *
 * The gap this closes is the oldest bug class in this codebase stated at a new level:
 * absence of a signal is never a signal. A device answers ICMP and SNMP, so it counts
 * as reachable and measured; meanwhile SSH — the source of tunnel state, next-hops and
 * underlay loss — has been refusing the login since the hardware was swapped, and the
 * tunnel rows on screen are days old while rendering exactly like fresh ones.
 *
 * On 6 October fourteen appliances sat between two and five days without one
 * successful SSH poll, against a dashboard reading 304 of 304 reachable and zero
 * unmeasured. The only evidence was a `Log::error` per sweep.
 *
 * Deliberately narrow in what it claims. It does not say the device is down — ICMP and
 * SNMP already answer that, and saying it twice would be wrong. It says: this poller
 * has not got in since X, so anything it feeds is that old.
 */
class PollHealthMonitor
{
    /** Alarm raised per device per transport. Scoped by device_id, like device-unreachable. */
    public const ALARM_PREFIX = 'poll-stale:';

    /**
     * How long without a success before it is a fault rather than a slow sweep.
     *
     * Set from measurement, not from a guess. On 6 October a full SSH sweep of the
     * Massey fleet took 103 minutes end to end: 115 devices inside one cycle, the
     * oldest of them 103 minutes behind the newest. A device sitting at 100 minutes
     * is therefore perfectly healthy and about to be polled.
     *
     * An earlier draft of this used 120 minutes — 1.2x the sweep, seventeen minutes
     * of headroom. That is far too tight. Sweep duration is not a constant: it grows
     * with the fleet, and it grows fastest when devices START failing, because every
     * refused login costs a full connect timeout before the sweep moves on. The very
     * condition this alarm exists to detect is the condition that lengthens the sweep
     * and would have tipped a 120-minute threshold into alarming on the whole fleet
     * at once.
     *
     * Six hours is ~3.5x the measured sweep. It costs nothing in detection: the
     * eighteen genuinely-failing devices on that same day were at a MINIMUM of 1,027
     * minutes, seventeen hours, so the gap between healthy and broken is enormous and
     * there is no reason to crowd the healthy end of it.
     */
    public const DEFAULT_STALE_MINUTES = 360;

    /** Record that a poller got in. Clears the failure streak. */
    public static function success(Device $device, string $transport): void
    {
        DevicePollHealth::updateOrCreate(
            ['device_id' => $device->id, 'transport' => $transport],
            ['last_success_at' => now(), 'consecutive_failures' => 0, 'last_error' => null],
        );
    }

    /**
     * Record that a poller could not get in.
     *
     * $reason must already be safe for display — SshError::safe output, never a raw
     * library message, which can echo a credential back.
     */
    public static function failure(Device $device, string $transport, ?string $reason = null): void
    {
        $row = DevicePollHealth::firstOrNew(['device_id' => $device->id, 'transport' => $transport]);

        $row->fill([
            'last_failure_at' => now(),
            'consecutive_failures' => (int) $row->consecutive_failures + 1,
            'last_error' => $reason ? mb_substr($reason, 0, 255) : $row->last_error,
        ])->save();
    }

    /**
     * Raise an alarm for every device a poller has stopped reaching, and clear the
     * ones that have come back.
     *
     * @return array{opened: int, cleared: int}
     */
    public function sweep(?int $staleMinutes = null): array
    {
        $minutes = $staleMinutes ?? (int) config('polling.stale_minutes', self::DEFAULT_STALE_MINUTES);
        $opened = $cleared = 0;

        // Resolved ONCE for the whole sweep. Device::maintenanceUntil() queries per
        // call, and this runs over every polled device on every devices:monitor
        // cycle — per-device it would be hundreds of queries a minute for an answer
        // that cannot change inside one pass.
        $suppressed = $this->suppressedDeviceIds();

        foreach (DevicePollHealth::TRANSPORTS as $transport) {
            $rows = DevicePollHealth::with('device.site:id,name')
                ->where('transport', $transport)
                ->get();

            foreach ($rows as $row) {
                $device = $row->device;
                if (! $device) {
                    continue;
                }

                $stale = $row->last_success_at === null
                    ? $row->created_at?->lt(now()->subMinutes($minutes))
                    : $row->last_success_at->lt(now()->subMinutes($minutes));

                // Planned work is not a fault. The device page still shows the stale
                // age, so a quiet dashboard never reads as "the box is fine".
                if ($stale && ! isset($suppressed[$device->id])) {
                    $opened += $this->open($device, $row, $transport, $minutes) ? 1 : 0;
                } else {
                    $cleared += $this->clear($device, $transport) ? 1 : 0;
                }
            }
        }

        if ($opened || $cleared) {
            Log::info('Poll-health sweep', compact('opened', 'cleared') + ['stale_minutes' => $minutes]);
        }

        return ['opened' => $opened, 'cleared' => $cleared];
    }

    /**
     * Device ids covered by an active maintenance window — global, per-site or
     * per-device — as a lookup, in one query instead of one per device.
     *
     * @return array<int, true>
     */
    private function suppressedDeviceIds(): array
    {
        $windows = MaintenanceWindow::active()->get(['site_id', 'device_id']);

        if ($windows->isEmpty()) {
            return [];
        }

        // A global window (neither site nor device set) covers everything.
        if ($windows->contains(fn ($w) => $w->site_id === null && $w->device_id === null)) {
            return Device::pluck('id')->flip()->map(fn () => true)->all();
        }

        $out = [];
        foreach ($windows->whereNotNull('device_id')->pluck('device_id') as $id) {
            $out[(int) $id] = true;
        }

        $siteIds = $windows->whereNotNull('site_id')->pluck('site_id')->unique();
        if ($siteIds->isNotEmpty()) {
            foreach (Device::whereIn('site_id', $siteIds)->pluck('id') as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    private function open(Device $device, DevicePollHealth $row, string $transport, int $minutes): bool
    {
        $alarmId = self::ALARM_PREFIX.$transport;
        $age = $row->stale_minutes;
        $label = strtoupper($transport);

        $description = "{$label} polling has not succeeded on this device for "
            .$this->humanAge($age).'. '
            // Say what it costs, not just that it happened — the operator needs to know
            // which data on screen is now untrustworthy.
            .($transport === DevicePollHealth::TRANSPORT_SSH
                ? 'Tunnel state, next-hops and underlay loss for this device are that old.'
                : 'Interface, health and alarm data for this device are that old.')
            .($row->last_error ? " Last error: {$row->last_error}" : '');

        $alarm = DeviceAlarm::firstOrNew(['device_id' => $device->id, 'alarm_id' => $alarmId]);

        if (! $alarm->exists) {
            $alarm->fill([
                // Warning, not critical. The device itself may be perfectly healthy —
                // what is broken is our view of it, and overstating that competes with
                // real outages for the NOC's attention.
                'severity' => 'warning',
                'description' => $description,
                'first_seen_at' => now(),
                'active_on_device' => true,
            ])->save();

            return true;
        }

        // Still stale after a manual clear and a recovery: a genuine new occurrence,
        // so it reopens with a fresh ticket exactly as device-unreachable does.
        if ($alarm->cleared_at !== null && ! $alarm->active_on_device) {
            $alarm->fill([
                'ticket_number' => DeviceAlarm::generateTicketNumber(),
                'severity' => 'warning',
                'description' => $description,
                'first_seen_at' => now(),
                'cleared_at' => null,
                'cleared_manually' => false,
                'active_on_device' => true,
            ])->save();

            return true;
        }

        // Open already: keep the age current so the row does not freeze at the
        // figure it had when it opened.
        $alarm->fill(['description' => $description, 'active_on_device' => true])->save();

        return false;
    }

    private function clear(Device $device, string $transport): bool
    {
        $alarmId = self::ALARM_PREFIX.$transport;

        $recovered = DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', $alarmId)
            ->whereNull('cleared_at')
            ->get()
            ->each(fn (DeviceAlarm $a) => $a->update([
                'cleared_at' => now(),
                'cleared_manually' => false,
                'active_on_device' => false,
            ]));

        DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', $alarmId)
            ->whereNotNull('cleared_at')
            ->where('active_on_device', true)
            ->update(['active_on_device' => false]);

        return $recovered->isNotEmpty();
    }

    private function humanAge(?int $minutes): string
    {
        if ($minutes === null) {
            return 'as long as it has been monitored';
        }
        if ($minutes < 120) {
            return "{$minutes} minutes";
        }
        if ($minutes < 2880) {
            return round($minutes / 60).' hours';
        }

        return round($minutes / 1440).' days';
    }
}
