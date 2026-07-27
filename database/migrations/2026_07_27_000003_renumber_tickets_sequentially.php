<?php

use App\Models\DeviceAlarm;
use App\Models\InterfaceAlert;
use App\Models\TunnelAlert;
use App\Support\TicketNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** table => [model, ticket type] */
    private const TARGETS = [
        'device_alarms' => [DeviceAlarm::class, TicketNumber::TYPE_ALARM],
        'interface_alerts' => [InterfaceAlert::class, TicketNumber::TYPE_INTERFACE],
        'tunnel_alerts' => [TunnelAlert::class, TicketNumber::TYPE_TUNNEL],
    ];

    /**
     * Renumber every existing ticket onto the sequential scheme.
     *
     * The old random numbers are preserved in legacy_ticket_number rather than
     * discarded. Some of them have already been quoted to an ISP or written into a
     * dispatch note, and a number an engineer is holding on a sticky note must not
     * become a dead end — search matches either column.
     *
     * Ordering is by when the ticket was opened, so the sequence reflects the order
     * events actually happened rather than row insertion order.
     *
     * circuit_alerts is deliberately untouched: its ticket_number holds the ISP's
     * own reference, entered by an operator, not a number this system issues.
     */
    public function up(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->string('legacy_ticket_number')->nullable()->after('ticket_number')->index();
            });
        }

        foreach (self::TARGETS as $table => [$model, $type]) {
            $orderColumn = Schema::hasColumn($table, 'first_seen_at') ? 'first_seen_at' : 'started_at';
            $sequence = 0;

            $model::orderBy($orderColumn)->orderBy('id')->chunkById(500, function ($rows) use (&$sequence, $type) {
                foreach ($rows as $row) {
                    $sequence++;
                    $row->updateQuietly([
                        // Keep the old number reachable; null if it never had one.
                        'legacy_ticket_number' => $row->ticket_number,
                        'ticket_number' => TicketNumber::format($type, $sequence),
                    ]);
                }
            });

            // Live traffic must continue after the highest number we just issued.
            TicketNumber::reserveThrough($type, $sequence);
        }

        // Now that values are unique by construction, enforce it. This is the real
        // fix for the old check-then-write race; the sequence table alone would
        // still let a bug slip a duplicate through.
        foreach (array_keys(self::TARGETS) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unique('ticket_number', "{$table}_ticket_number_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique("{$table}_ticket_number_unique");
            });
        }

        // Restore the numbers people may still be holding.
        foreach (self::TARGETS as $table => [$model, $type]) {
            $model::whereNotNull('legacy_ticket_number')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $row->updateQuietly(['ticket_number' => $row->legacy_ticket_number]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['legacy_ticket_number']);
                $blueprint->dropColumn('legacy_ticket_number');
            });
        }
    }
};
