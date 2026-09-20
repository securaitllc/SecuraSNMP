<?php

namespace App\Services;

use App\Models\Anomaly;
use App\Models\Circuit;
use Illuminate\Support\Collection;

/**
 * Many entities deviating together, in the same way, at the same moment, is ONE
 * event — almost always something that changed on the path they share.
 *
 * 19 September: the collector's internet egress was moved from one carrier to
 * another at 17:37. Every circuit's round-trip time shifted by about the same 7 ms,
 * and at 17:55 the baseline detector opened FIFTY separate latency anomalies. None
 * of them was wrong — latency really did change — but fifty alerts for one
 * deliberate action is noise, and noise is what buries the finding that matters.
 * The giveaway was in the data the whole time: those fifty spanned three different
 * carriers, so no carrier could be the cause. Only the path they have in common.
 *
 * This groups by metric and direction, requires the deviations to be close to each
 * other in TIME and similar in SIZE, and reports the group as one finding with its
 * members attached. It resolves nothing and suppresses nothing — the anomalies stay
 * exactly as they are. It only says: these belong together, and here is what they
 * have in common.
 */
class AnomalyCorrelation
{
    /** Fewer than this deviating together is a coincidence, not a pattern. */
    public const MIN_MEMBERS = 8;

    /** Detections this far apart are not the same event. */
    public const WINDOW_MINUTES = 10;

    /**
     * How alike the shifts must be. A shared path change moves everything by
     * roughly the same amount; two unrelated faults do not. Expressed as the
     * proportion of the median shift that a member may differ by.
     */
    public const SPREAD_TOLERANCE = 0.45;

    /**
     * @param  Collection<int, Anomaly>  $anomalies  the open anomalies to group
     * @return array<int, array<string, mixed>>
     */
    public function findings(Collection $anomalies): array
    {
        $out = [];

        foreach ($anomalies->groupBy(fn (Anomaly $a) => $a->metric.'|'.$a->direction) as $key => $group) {
            if ($group->count() < self::MIN_MEMBERS) {
                continue;
            }

            foreach ($this->byDetectionWindow($group) as $window) {
                if ($window->count() < self::MIN_MEMBERS) {
                    continue;
                }

                $finding = $this->describe($window);
                if ($finding !== null) {
                    $out[] = $finding;
                }
            }
        }

        // Biggest group first — that is the one event most worth explaining.
        usort($out, fn ($a, $b) => $b['members'] <=> $a['members']);

        return $out;
    }

    /**
     * Split a metric group into clusters of detections that happened together.
     *
     * @param  Collection<int, Anomaly>  $group
     * @return array<int, Collection<int, Anomaly>>
     */
    private function byDetectionWindow(Collection $group): array
    {
        $sorted = $group->sortBy(fn (Anomaly $a) => optional($a->detected_at)?->getTimestamp() ?? 0)->values();

        $clusters = [];
        $current = collect();
        $anchor = null;

        foreach ($sorted as $anomaly) {
            $at = $anomaly->detected_at;
            if ($at === null) {
                continue;
            }

            // Diff FORWARD from the anchor. Carbon 3 returns a signed difference, so
            // $at->diffInMinutes($anchor) on a later $at is negative — and negative is
            // always under the window, which would have swept every detection in the
            // fleet into one cluster no matter how far apart.
            if ($anchor === null || $anchor->diffInMinutes($at) <= self::WINDOW_MINUTES) {
                $anchor ??= $at;
                $current->push($anomaly);

                continue;
            }

            $clusters[] = $current;
            $current = collect([$anomaly]);
            $anchor = $at;
        }

        if ($current->isNotEmpty()) {
            $clusters[] = $current;
        }

        return $clusters;
    }

    /**
     * Describe a cluster, or reject it when the shifts are too dissimilar to be one
     * cause.
     *
     * @param  Collection<int, Anomaly>  $window
     * @return array<string, mixed>|null
     */
    private function describe(Collection $window): ?array
    {
        $shifts = $window->map(fn (Anomaly $a) => (float) $a->observed - (float) $a->baseline)
            ->filter(fn (float $v) => $v != 0.0)
            ->values();

        if ($shifts->count() < self::MIN_MEMBERS) {
            return null;
        }

        $median = $this->median($shifts->all());
        if ($median == 0.0) {
            return null;
        }

        // Members whose shift looks like the group's. If most of them do not, these
        // are separate problems that merely opened at a similar time.
        $alike = $shifts->filter(
            fn (float $v) => abs($v - $median) <= abs($median) * self::SPREAD_TOLERANCE
        )->count();

        if ($alike < self::MIN_MEMBERS || $alike / $shifts->count() < 0.7) {
            return null;
        }

        $first = $window->first();

        // Entities spanning several carriers is the strongest evidence that no carrier
        // is the cause. It is stated as a fact about the group, not as a conclusion.
        $carriers = $this->carriersOf($window);

        return [
            'metric' => $first->metric,
            'direction' => $first->direction,
            'members' => $window->count(),
            'shift' => round($median, 2),
            'baseline_median' => round($this->median($window->pluck('baseline')->map(fn ($v) => (float) $v)->all()), 2),
            'observed_median' => round($this->median($window->pluck('observed')->map(fn ($v) => (float) $v)->all()), 2),
            'detected_at' => optional($window->min('detected_at'))?->toIso8601String(),
            'entity_types' => $window->pluck('entity_type')->unique()->values()->all(),
            'carriers' => $carriers->all(),
            'anomaly_ids' => $window->pluck('id')->values()->all(),
            'reason' => $this->reason($window, $carriers->count()),
        ];
    }

    /**
     * The distinct carriers behind a group's circuits.
     *
     * @param  Collection<int, Anomaly>  $window
     * @return Collection<int, string>
     */
    private function carriersOf(Collection $window): Collection
    {
        $ids = $window->where('entity_type', 'circuit')->pluck('entity_id')->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Circuit::whereIn('id', $ids)
            ->pluck('isp_name')
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * One plain sentence an operator can act on — or decide to ignore, which is just
     * as valuable and is what fifty separate rows never allowed.
     *
     * @param  Collection<int, Anomaly>  $window
     */
    private function reason(Collection $window, int $carrierCount): string
    {
        $n = $window->count();
        $metric = $window->first()->metric;

        $shared = $carrierCount > 1
            ? " They span {$carrierCount} carriers, so the cause is on the path they share, not with any one carrier."
            : '';

        return "{$n} entities changed {$metric} together within minutes of each other, by a similar amount."
            .$shared
            .' A shift this uniform is one event — most often a route, egress or path change.';
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
