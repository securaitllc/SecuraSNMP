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
    /** table => [model, column ordering tickets by when the event opened] */
    private const TARGETS = [
        'device_alarms' => [DeviceAlarm::class, 'first_seen_at'],
        'interface_alerts' => [InterfaceAlert::class, 'started_at'],
        'tunnel_alerts' => [TunnelAlert::class, 'started_at'],
    ];

    /**
     * Reformat existing tickets to MSTK00000733.
     *
     * The earlier scheme numbered per type, so a device alarm and an interface alert
     * could both be sequence 1. The new format carries no type segment, which means
     * those two would collapse onto the same string. Numbers are therefore reissued
     * from a single global counter rather than reformatted in place — the old
     * sequence cannot simply be re-rendered.
     *
     * Ordering is by when each event opened, across all three tables, so the
     * resulting numbers still read chronologically.
     *
     * The counter is advanced through the highest number issued BEFORE any row is
     * written, so a poller raising an alarm midway through cannot be handed a number
     * this migration is about to use.
     *
     * Safe to re-run: rows already in the target format are skipped, and the original
     * pre-sequence number in legacy_ticket_number is never overwritten — that is the
     * number someone may have quoted to a carrier.
     */
    public function up(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            if (! Schema::hasColumn($table, 'legacy_ticket_number')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('legacy_ticket_number', 32)->nullable()->after('ticket_number')->index();
                });
            }
        }

        $pending = [];
        foreach (self::TARGETS as $table => [$model, $orderColumn]) {
            $rows = $model::query()
                ->where(fn ($q) => $q->whereNull('ticket_number')->orWhere('ticket_number', 'not like', 'MSTK%'))
                ->orderBy($orderColumn)
                ->orderBy('id')
                ->get(['id', 'ticket_number', 'legacy_ticket_number', $orderColumn]);

            foreach ($rows as $row) {
                $pending[] = [
                    'model' => $model,
                    'id' => $row->id,
                    'at' => $row->{$orderColumn},
                    'current' => $row->ticket_number,
                    'legacy' => $row->legacy_ticket_number,
                ];
            }
        }

        if ($pending === []) {
            return;
        }

        // Chronological across every table, so the global sequence reads in the order
        // events actually happened rather than table by table.
        usort($pending, fn ($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);

        // Claim the whole block up front. Drawing one at a time would interleave with
        // live pollers and scatter this migration's numbers through theirs.
        $first = TicketNumber::nextSequence();
        TicketNumber::reserveThrough($first + count($pending) - 1);

        foreach ($pending as $i => $entry) {
            $entry['model']::where('id', $entry['id'])->update([
                // Keep the ORIGINAL pre-sequence number. On a database that has already
                // been through the earlier renumber, legacy holds the number a carrier
                // was given; overwriting it with the interim format would lose that.
                'legacy_ticket_number' => $entry['legacy'] ?? $entry['current'],
                'ticket_number' => TicketNumber::format($first + $i),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversed. The pre-MSTK values are still in legacy_ticket_number and remain
        // searchable; rewriting live ticket numbers a second time to undo a formatting
        // change would create more confusion than it resolves.
    }
};
