<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAlarm;

/**
 * Turns FortiGate log lines into alarms.
 *
 * DNS and UTM failures are the gap SNMP cannot close. A FortiGate exposes no OID
 * for "the DNS filter cannot reach FortiGuard" or "rating lookups are timing out";
 * it writes a log line and keeps forwarding traffic — unrated and unfiltered. From
 * the outside everything looks healthy, which is the worst shape a failure can
 * take on a security device.
 *
 * Rules live in config/fortigate.php, matched against the message text, and all
 * occurrences of one condition collapse into ONE alarm (`fgl:<key>`) rather than
 * one alarm per log line — a broken resolver can write hundreds a minute.
 *
 * The alarm id prefix is deliberately NOT the `fg:` the SNMP poller owns. That
 * poller clears any of its own alarms it no longer sees, and a log-derived alarm
 * is never "seen" in an SNMP table, so sharing a prefix would have had the two
 * fighting each other every 90 seconds.
 */
class FortiGateLogAlarms
{
    public const ALARM_PREFIX = 'fgl:';

    /**
     * Raise or refresh an alarm for a log line, if any rule matches it.
     *
     * Returns the alarm when one was raised or refreshed, null when the line was
     * ordinary traffic logging — which is almost all of it.
     */
    public static function evaluate(Device $device, string $message): ?DeviceAlarm
    {
        $rule = self::matchRule($message);
        if ($rule === null) {
            return null;
        }

        $alarmId = self::ALARM_PREFIX.$rule['key'];
        $alarm = DeviceAlarm::firstOrNew(['device_id' => $device->id, 'alarm_id' => $alarmId]);

        $alarm->description = $rule['description'];
        $alarm->severity = $rule['severity'];

        if (! $alarm->exists) {
            $alarm->first_seen_at = now();
            $alarm->cleared_at = null;
        } elseif ($alarm->cleared_at !== null && ! $alarm->active_on_device) {
            // It went quiet long enough to clear and has come back — a recurrence,
            // so a fresh ticket makes it countable.
            $alarm->ticket_number = DeviceAlarm::generateTicketNumber();
            $alarm->first_seen_at = now();
            $alarm->cleared_at = null;
            $alarm->cleared_by = null;
            $alarm->clear_note = null;
            $alarm->cleared_manually = false;
            $alarm->acknowledged_at = null;
            $alarm->acknowledged_by = null;
            $alarm->ack_note = null;
        } elseif ($alarm->cleared_at !== null) {
            // Manually cleared by the NOC while the condition is still logging.
            // Respect that: touch nothing, do not resurrect.
            return $alarm;
        }

        $alarm->active_on_device = true;
        // Bumped on every matching line — this is what the quiet-period sweep reads
        // to decide the condition has stopped.
        $alarm->updated_at = now();
        $alarm->save();

        return $alarm;
    }

    /**
     * Clear log-derived alarms that have stopped recurring.
     *
     * A log line is an event, not a state, so nothing ever "goes missing from the
     * table" to clear these. Silence is the only signal available.
     */
    public static function clearQuiet(): int
    {
        $minutes = max(1, (int) config('fortigate.log_alarm_quiet_minutes', 30));
        $cutoff = now()->subMinutes($minutes);

        $stale = DeviceAlarm::where('alarm_id', 'like', self::ALARM_PREFIX.'%')
            ->whereNull('cleared_at')
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stale as $alarm) {
            $alarm->cleared_at = now();
            $alarm->active_on_device = false;
            $alarm->save();
        }

        return $stale->count();
    }

    /** @return array{key: string, match: string, severity: string, description: string}|null */
    private static function matchRule(string $message): ?array
    {
        foreach ((array) config('fortigate.log_rules', []) as $rule) {
            if (empty($rule['match']) || empty($rule['key'])) {
                continue;
            }

            // A bad regex in config must not take the syslog listener down with it.
            if (@preg_match($rule['match'], $message) === 1) {
                return [
                    'key' => $rule['key'],
                    'match' => $rule['match'],
                    'severity' => $rule['severity'] ?? 'warning',
                    'description' => $rule['description'] ?? $rule['key'],
                ];
            }
        }

        return null;
    }
}
