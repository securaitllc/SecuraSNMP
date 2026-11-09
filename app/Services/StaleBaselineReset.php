<?php

namespace App\Services;

use App\Models\Anomaly;
use Illuminate\Support\Facades\Log;

/**
 * Clears anomalies whose baseline describes a world that no longer exists.
 *
 * A robust-z detector compares now against the entity's own recent history. That is
 * the right design until the history stops being relevant — and a deliberate path
 * change does exactly that. On 19 September the collector's internet egress moved to
 * a different carrier, every circuit's round-trip time rose by about 7 ms, and the
 * detector opened thirty-four latency anomalies against a baseline of 4.1 ms that
 * belonged to a path nobody uses any more.
 *
 * None of them was wrong and none of them would ever resolve. The observed value is
 * genuinely above the old baseline, and it will stay there, so the finding stands
 * until someone clears it by hand. Grouping them under one heading (see
 * AnomalyCorrelation) labels the noise; it does not remove it.
 *
 * When many entities shift by a similar amount and then SETTLE at the new value, the
 * new value is the normal. Resolving those anomalies lets the baseline re-learn from
 * the traffic that is actually flowing, and a real deviation from the new normal
 * opens a fresh finding the moment it happens.
 *
 * Deliberately conservative: it needs a crowd, a consistent shift, and a period of
 * stability at the new level. One entity drifting on its own is a finding, not a
 * re-baseline, and always will be.
 */
class StaleBaselineReset
{
    /** A shift has to be fleet-wide to be a path change rather than a fault. */
    public const MIN_MEMBERS = 8;

    /**
     * How long the new value must have held before it counts as the new normal.
     * Long enough that a genuine sustained fault is not reset out from under the
     * NOC while they are still working it.
     */
    public const SETTLED_HOURS = 6;

    /** Metrics where a uniform fleet-wide shift means a path changed, not a fault. */
    public const RESETTABLE = ['latency'];

    /**
     * @return int anomalies resolved
     */
    public function run(): int
    {
        $resolved = 0;

        foreach (self::RESETTABLE as $metric) {
            $open = Anomaly::open()->where('metric', $metric)->get();

            foreach ($open->groupBy('direction') as $group) {
                if ($group->count() < self::MIN_MEMBERS) {
                    continue;
                }

                // Every member must have been sitting at its new value for a while.
                // last_seen_at moving with detected_at still far behind is exactly
                // the shape of "this changed once and stayed changed".
                $settled = $group->filter(
                    fn (Anomaly $a) => $a->detected_at !== null
                        && $a->detected_at->lt(now()->subHours(self::SETTLED_HOURS))
                );

                if ($settled->count() < self::MIN_MEMBERS) {
                    continue;
                }

                $shifts = $settled->map(fn (Anomaly $a) => (float) $a->observed - (float) $a->baseline)
                    ->filter(fn (float $v) => $v != 0.0)->values();

                if ($shifts->count() < self::MIN_MEMBERS) {
                    continue;
                }

                $median = $this->median($shifts->all());
                if ($median == 0.0) {
                    continue;
                }

                // Only the members that moved the way the crowd did. A circuit that
                // shifted three times as far is not part of this event and keeps its
                // finding.
                $moved = $settled->filter(function (Anomaly $a) use ($median) {
                    $shift = (float) $a->observed - (float) $a->baseline;

                    return $shift != 0.0 && abs($shift - $median) <= abs($median);
                });

                if ($moved->count() < self::MIN_MEMBERS) {
                    continue;
                }

                Anomaly::whereIn('id', $moved->pluck('id'))->update(['resolved_at' => now()]);
                $resolved += $moved->count();

                Log::info('Stale baselines reset after a settled fleet-wide shift', [
                    'metric' => $metric,
                    'entities' => $moved->count(),
                    'median_shift' => round($median, 2),
                ]);
            }
        }

        return $resolved;
    }

    /** @param array<int, float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }
}
