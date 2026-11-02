<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Issues human-quotable ticket numbers: MSTK00000733.
 *
 * Sequential rather than random. The previous generator drew an 8-digit number,
 * checked the table, and inserted — check-then-write with no unique constraint,
 * while three poller loops run concurrently. Two callers could clear the existence
 * check in the same instant and both write; randomness made that unlikely, not
 * impossible.
 *
 * The counter is GLOBAL, not per type. The format carries no type segment, so a
 * per-type counter would hand the same number to a device alarm and an interface
 * alert and produce a genuine duplicate. One counter across every ticket-issuing
 * table is what makes the number unique on its own.
 *
 * The row is locked for the duration of the read-increment-write, so concurrency is
 * settled by the database rather than by hoping, and a unique index on each
 * ticket_number column is the backstop.
 */
class TicketNumber
{
    /** Single counter shared by every ticket-issuing table. */
    public const SEQUENCE = 'global';

    /** Zero-padding width: MSTK followed by 8 digits. */
    private const WIDTH = 8;

    private const PREFIX = 'MSTK';

    /** The next ticket, e.g. MSTK00000733. Safe under concurrent pollers. */
    public static function next(): string
    {
        return self::format(self::nextSequence());
    }

    public static function format(int $sequence): string
    {
        return self::PREFIX.str_pad((string) $sequence, self::WIDTH, '0', STR_PAD_LEFT);
    }

    /**
     * Claim the next value from the shared counter.
     *
     * Public so a backfill draws from the same sequence live traffic uses — which is
     * what stops a reformat and a concurrent poller issuing the same number.
     */
    public static function nextSequence(): int
    {
        return DB::transaction(function (): int {
            $row = DB::table('ticket_sequences')->where('type', self::SEQUENCE)->lockForUpdate()->first();

            if ($row === null) {
                try {
                    DB::table('ticket_sequences')->insert([
                        'type' => self::SEQUENCE,
                        'next_value' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 1;
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // A concurrent caller won the race to create it; take the next.
                    $row = DB::table('ticket_sequences')->where('type', self::SEQUENCE)->lockForUpdate()->first();
                }
            }

            $value = (int) $row->next_value;
            DB::table('ticket_sequences')
                ->where('type', self::SEQUENCE)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        });
    }

    /** Move the counter past a known point so a backfill cannot collide with live traffic. */
    public static function reserveThrough(int $lastUsed): void
    {
        DB::table('ticket_sequences')->updateOrInsert(
            ['type' => self::SEQUENCE],
            ['next_value' => $lastUsed + 1, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
