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
        // Chunked, not ->get(): cleared alarms accumulate without bound, and a
        // full-table load into memory is the failure this codebase has already hit
        // once (a 6h ping-history load over the fleet OOM'd). Delete by id per
        // chunk rather than per row, so this stays 2 queries per 500 rows.
        DeviceAlarm::whereNotNull('cleared_at')
            ->select(['id', 'alarm_id', 'active_on_device'])
            ->chunkById(500, function ($alarms) {
                $ids = $alarms
                    ->filter(fn ($alarm) => self::isDeletable((string) $alarm->alarm_id, (bool) $alarm->active_on_device))
                    ->pluck('id');

                if ($ids->isNotEmpty()) {
                    DeviceAlarm::whereIn('id', $ids)->delete();
                }
            });
    }

    public function down(): void
    {
        // No-op: the deleted rows held no recoverable information.
    }

    /**
     * Whether a cleared row is safe to delete.
     *
     * The two malformed shapes need different treatment because they differ in
     * whether a poller could ever act on them again:
     *
     *   - Bare-numeric ('1'..'4') — a retired poller wrote the SNMP walk INDEX as
     *     the identifier. Nothing emits this shape, and nothing reconciles it
     *     either: EdgeConnectAlarmPoller scopes its reconcile to 'ec:%' and
     *     DeviceMonitor to 'device-unreachable'. So active_on_device on these rows
     *     is meaningless residue that will never be cleared by any code path —
     *     guarding on it would strand them permanently. Delete unconditionally.
     *
     *   - 'ec::' — lives inside the live 'ec:' namespace that the reconcile query
     *     matches. Respect the no-resurrect state: if it is cleared but still
     *     active on the device, the NOC cleared it while the appliance kept
     *     reporting, and deleting the row would let firstOrNew() reopen it with a
     *     fresh ticket. Keep those.
     */
    private static function isDeletable(string $alarmId, bool $activeOnDevice): bool
    {
        if (preg_match('/^\d+$/', $alarmId) === 1) {
            return true;
        }

        return $alarmId === 'ec::' && ! $activeOnDevice;
    }
};
