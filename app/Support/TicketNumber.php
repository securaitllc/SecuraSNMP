<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Issues human-quotable ticket numbers: MSIT-ALM-000123.
 *
 * Replaces a random 8-digit generator that read the table, picked a number, and
 * inserted it. That was check-then-write with no unique constraint behind it —
 * three poller loops run concurrently, so two could clear the existence check in
 * the same instant and both write. Randomness made a collision unlikely, not
 * impossible, and a duplicate ticket number is exactly the kind of fault a NOC
 * discovers at the worst moment.
 *
 * The counter lives in its own row and is incremented inside a transaction with a
 * row lock, so concurrency is resolved by the database rather than by hoping. A
 * unique index on each ticket_number column is the backstop.
 *
 * Sequential also means a ticket number carries information: MSIT-ALM-000900 is
 * plainly more recent than MSIT-ALM-000100, which a random number never told you.
 */
class TicketNumber
{
    /** Device alarms (SNMP-sourced). */
    public const TYPE_ALARM = 'ALM';

    /** Interface up/down alerts. */
    public const TYPE_INTERFACE = 'IFC';

    /** SD-WAN tunnel alerts. */
    public const TYPE_TUNNEL = 'TUN';

    /** Zero-padding width. Six digits is a million tickets per type. */
    private const WIDTH = 6;

    /**
     * The next ticket for a type, e.g. MSIT-ALM-000123.
     *
     * Safe under concurrent pollers: the row is locked for the duration of the
     * read-increment-write, so two callers serialise rather than collide.
     */
    public static function next(string $type): string
    {
        $sequence = DB::transaction(function () use ($type): int {
            $row = DB::table('ticket_sequences')->where('type', $type)->lockForUpdate()->first();

            if ($row === null) {
                // First ticket of this type. A concurrent caller may win the race to
                // insert, so fall back to incrementing whatever landed.
                try {
                    DB::table('ticket_sequences')->insert([
                        'type' => $type,
                        'next_value' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 1;
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    $row = DB::table('ticket_sequences')->where('type', $type)->lockForUpdate()->first();
                }
            }

            $value = (int) $row->next_value;
            DB::table('ticket_sequences')
                ->where('type', $type)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        });

        return self::format($type, $sequence);
    }

    public static function format(string $type, int $sequence): string
    {
        $prefix = config('monitoring.ticket_prefix', 'MSIT');

        return sprintf('%s-%s-%0'.self::WIDTH.'d', $prefix, $type, $sequence);
    }

    /**
     * Move a type's counter past a known point, so a backfill and live traffic
     * cannot issue the same number.
     */
    public static function reserveThrough(string $type, int $lastUsed): void
    {
        DB::table('ticket_sequences')->updateOrInsert(
            ['type' => $type],
            ['next_value' => $lastUsed + 1, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
