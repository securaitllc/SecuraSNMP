<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The clock the network is operated on.
 *
 * Storage stays UTC — every timestamp in the database is an unambiguous instant,
 * which is the only sane way to keep them. But almost nothing an operator asks is
 * about instants. "Does this contract expire this week", "what did we push
 * yesterday", "is this much traffic normal for this hour" are all questions about
 * a WALL CLOCK, and Massey's wall clock is Eastern.
 *
 * Answering them in UTC puts the day boundary at 8pm local: between 8pm and
 * midnight a countdown loses a day, a daily rollup covers 8pm-to-8pm, and an
 * hour-of-day baseline files evening traffic under the next morning. None of
 * that shows up as an error — the numbers are simply wrong by one, which is the
 * hardest kind of wrong to notice.
 *
 * So: store in UTC, decide in ops time. Everything that buckets by day, hour or
 * date goes through here.
 */
class OpsTime
{
    public static function zone(): string
    {
        return (string) config('app.ops_timezone', 'America/New_York');
    }

    /** Now, as the operator's clock reads it. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /** Today's date on the operator's clock — 'Y-m-d'. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Hour of day (0-23) on the operator's clock. */
    public static function hour(): int
    {
        return (int) self::now()->format('G');
    }

    /** The hour of day a stored instant falls in, read on the operator's clock. */
    public static function hourOf(\DateTimeInterface $at): int
    {
        return (int) CarbonImmutable::instance($at)->setTimezone(self::zone())->format('G');
    }

    /** The date a stored instant falls on, read on the operator's clock. */
    public static function dateOf(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone(self::zone())->toDateString();
    }

    /**
     * Midnight local, returned as the UTC instant it happened at.
     *
     * Query bindings are compared against UTC columns, so a local-midnight Carbon
     * has to be converted back before it touches the database — otherwise it is
     * serialised with its local wall time and silently reads four hours off.
     */
    public static function startOfLocalDay(?CarbonImmutable $day = null): CarbonImmutable
    {
        return ($day ?? self::now())->setTimezone(self::zone())->startOfDay()->setTimezone('UTC');
    }

    /** Whole days from today (ops clock) to a stored date. Negative once past. */
    public static function daysUntil(?\DateTimeInterface $date): ?int
    {
        if ($date === null) {
            return null;
        }

        $from = Carbon::parse(self::today());
        $to = Carbon::parse(CarbonImmutable::instance($date)->toDateString());

        return (int) $from->diffInDays($to, false);
    }
}
