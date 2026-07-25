<?php

use App\Models\DeviceAlarm;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Remove alarm rows whose alarm_id was never a real identifier.
     *
     * Two shapes exist in Massey production, both from poller versions that no
     * longer run:
     *   - 'ec::'      — a failed SNMP row with neither type-id nor source, written
     *                   before the ghost guard in EdgeConnectAlarmPoller landed.
     *   - '1'…'4'     — bare walk indices from an early poller that used the row
     *                   index as the identifier instead of 'ec:<typeId>:<source>'.
     *
     * They carry no diagnostic value and cannot be referenced by a NOC. Valid ids
     * ('ec:<typeId>:<source>', 'device-unreachable') never match this predicate.
     *
     * Guard: only CLEARED rows are removed. An open alarm is never deleted, even
     * if malformed — a live problem must not silently vanish from the NOC.
     */
    public function up(): void
    {
        DeviceAlarm::whereNotNull('cleared_at')
            ->get()
            ->filter(fn (DeviceAlarm $alarm) => self::isMalformed((string) $alarm->alarm_id))
            ->each(fn (DeviceAlarm $alarm) => $alarm->delete());
    }

    public function down(): void
    {
        // No-op: the deleted rows held no recoverable information.
    }

    /** True for 'ec::' and for bare-numeric ids; false for every valid alarm id. */
    private static function isMalformed(string $alarmId): bool
    {
        return $alarmId === 'ec::' || preg_match('/^\d+$/', $alarmId) === 1;
    }
};
