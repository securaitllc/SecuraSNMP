<?php

namespace App\Support;

use Illuminate\Support\Collection;

class MetricDownsampler
{
    /**
     * Bound a response-time series to ~$cap points before it goes to a chart.
     *
     * A densely-polled device (e.g. one pinged by several loops) can hold 2,600
     * points/day — 13k over a week, a 2.4 MB payload — and rendering that many in
     * ApexCharts freezes the browser tab. Decimate to ~$cap, but ALWAYS keep the
     * null (unreachable) points so outage stretches and their shaded bands stay
     * exact; only the healthy line is thinned.
     *
     * @param  Collection<int,object>  $metrics  ordered by recorded_at, each with a response_time_ms
     * @return Collection<int,object>
     */
    public static function cap(Collection $metrics, int $cap = 800): Collection
    {
        if ($metrics->count() <= $cap) {
            return $metrics;
        }

        $stride = (int) ceil($metrics->count() / $cap);

        return $metrics->values()
            ->filter(fn ($m, $i) => $i % $stride === 0 || $m->response_time_ms === null)
            ->values();
    }

    /**
     * Plain stride decimation to ~$cap points, for series with no null-outage
     * semantics to preserve (traffic/errors/discards). Groups by $groupKey and
     * caps each series independently so one dense interface can't flood the chart
     * and a multi-series (whole-device) request stays bounded per line.
     *
     * @param  Collection<int,object>  $metrics  ordered by recorded_at
     * @return Collection<int,object>
     */
    public static function decimate(Collection $metrics, string $groupKey, int $cap = 800): Collection
    {
        return $metrics->groupBy($groupKey)->flatMap(function (Collection $series) use ($cap) {
            if ($series->count() <= $cap) {
                return $series;
            }
            $stride = (int) ceil($series->count() / $cap);

            return $series->values()->filter(fn ($m, $i) => $i % $stride === 0)->values();
        })->values();
    }
}
