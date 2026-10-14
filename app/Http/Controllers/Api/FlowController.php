<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FlowRecord;
use App\Models\FlowRollup;
use App\Services\Flow\KqlFlowQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read API behind the Flows tab: the KPI summary, top talkers, per-app breakdown, and
 * the KQL-subset flow search. All reads scoped to a time window (the UI's time picker,
 * default last 6h). Aggregates from the raw flow_records — bounded by the 48h retention.
 */
class FlowController extends Controller
{
    public function __construct(private KqlFlowQuery $kql)
    {
    }

    /** Devices actually exporting flows in the window, busiest first — so the UI can
     *  default to one with data instead of the alphabetically-first device (which may
     *  export nothing). */
    public function exporters(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        $rows = FlowRecord::whereBetween('recorded_at', [$from, $to])->whereNotNull('device_id')
            ->selectRaw('device_id, SUM(bytes) as total, COUNT(*) as flows')
            ->groupBy('device_id')->orderByDesc('total')->limit(50)->get();

        $names = Device::whereIn('id', $rows->pluck('device_id'))->pluck('name', 'id');

        return response()->json(['exporters' => $rows->map(fn ($r) => [
            'device_id' => $r->device_id,
            'name' => $names[$r->device_id] ?? null,
            'bytes' => (int) $r->total,
            'flows' => (int) $r->flows,
        ])]);
    }

    /** KPI strip for one device's flows. */
    public function summary(Device $device, Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        $base = fn () => FlowRecord::where('device_id', $device->id)->whereBetween('recorded_at', [$from, $to]);

        $topTalker = $base()->selectRaw('src_ip, dst_ip, SUM(bytes) as bytes')
            ->groupBy('src_ip', 'dst_ip')->orderByDesc('bytes')->first();
        $topApp = $base()->selectRaw('app, SUM(bytes) as bytes')
            ->groupBy('app')->orderByDesc('bytes')->first();

        return response()->json([
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'total_bytes' => (int) $base()->sum('bytes'),
            'flows' => (int) $base()->count(),
            // Distinct (src,dst) pairs — a portable count over a DISTINCT subquery
            // (COUNT(DISTINCT a,b) and `||` concat both diverge SQLite vs MySQL).
            'conversations' => (int) DB::query()->fromSub(
                $base()->select('src_ip', 'dst_ip')->distinct(), 't'
            )->count(),
            'top_talker' => $topTalker ? ['src_ip' => $topTalker->src_ip, 'dst_ip' => $topTalker->dst_ip, 'bytes' => (int) $topTalker->bytes] : null,
            'top_app' => $topApp ? ['app' => $topApp->app, 'bytes' => (int) $topApp->bytes] : null,
        ]);
    }

    /** Top talkers (src → dst) for the window, by bytes / packets / flows. */
    public function topTalkers(Device $device, Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        $metric = in_array($request->query('metric'), ['packets', 'flows'], true) ? $request->query('metric') : 'bytes';

        $rows = FlowRecord::where('device_id', $device->id)->whereBetween('recorded_at', [$from, $to])
            ->selectRaw('src_ip, dst_ip, MAX(app) as app, MAX(app_category) as app_category, MAX(protocol) as protocol, MAX(dst_port) as dst_port, SUM(bytes) as bytes, SUM(packets) as packets, COUNT(*) as flows')
            ->groupBy('src_ip', 'dst_ip')
            ->orderByDesc($metric)
            ->limit(20)->get();

        $total = (int) FlowRecord::where('device_id', $device->id)->whereBetween('recorded_at', [$from, $to])->sum('bytes');

        return response()->json(['talkers' => $rows, 'total_bytes' => $total, 'metric' => $metric]);
    }

    /** Per-application breakdown (DPI) for the window. */
    public function apps(Device $device, Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        $rows = FlowRecord::where('device_id', $device->id)->whereBetween('recorded_at', [$from, $to])
            ->selectRaw('app, MAX(app_category) as app_category, SUM(bytes) as bytes, SUM(packets) as packets, COUNT(*) as flows')
            ->groupBy('app')
            ->orderByDesc('bytes')
            ->limit(15)->get();

        return response()->json(['apps' => $rows, 'total_bytes' => (int) $rows->sum('bytes')]);
    }

    /** KQL-subset flow search. Returns matching flows, or an aggregate when the query summarizes. */
    public function search(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');
        [$from, $to] = $this->window($request);

        $base = FlowRecord::query()->whereBetween('recorded_at', [$from, $to])
            ->when($request->query('device_id'), fn ($query, $id) => $query->where('device_id', $id));

        try {
            $this->kql->filter($base, $q);
            $pipeline = $this->kql->parsePipeline($q);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Aggregating query: | summarize sum(Bytes) by <Field> [| top N].
        // NOTE: alias to grp/total, NOT key/value — the latter are RESERVED words in
        // MySQL (SQLite doesn't reserve them, which is why the unit test passed but prod
        // 500'd). The JSON keeps the key/value contract via the map below.
        if ($pipeline) {
            $rows = $base->selectRaw("{$pipeline['by']} as grp, SUM({$pipeline['metric']}) as total, COUNT(*) as flows")
                ->groupBy($pipeline['by'])
                ->orderByDesc('total')
                ->limit($pipeline['top'])->get()
                ->map(fn ($r) => ['key' => $r->grp, 'value' => (int) $r->total, 'flows' => (int) $r->flows]);

            return response()->json(['mode' => 'summarize', 'by' => $pipeline['by_label'], 'metric' => $pipeline['metric'], 'rows' => $rows]);
        }

        $count = (clone $base)->count();
        $bytes = (int) (clone $base)->sum('bytes');
        $rows = $base->with('device:id,name')->orderByDesc('recorded_at')->limit(500)->get();

        return response()->json(['mode' => 'rows', 'count' => $count, 'bytes' => $bytes, 'rows' => $rows]);
    }

    /**
     * Fleet-wide overview (summary + top talkers + per-app), optionally narrowed by a
     * KQL filter. This is the Flows page's default view — ALL collected flows, no device
     * dropdown; add `Device == "…"` to the query to scope it.
     */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        $q = (string) $request->query('q', '');

        // Validate the query up front so a bad KQL still 422s (not a cached error).
        try {
            $this->kql->filter(FlowRecord::query(), $q === '' ? '' : $q);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Cache on a 60s bucket with a 90s TTL. The bucket MUST be longer than the slowest
        // query (a wide-window aggregate can take ~20s) — a 20s bucket rolled over before
        // the result could be reused, so warm loads recomputed. 60s means one compute per
        // minute per (window,filter); everything else serves the cache instantly.
        $bucket = (int) floor(now()->timestamp / 60);
        $key = 'flows.overview.'.md5($q.'|'.$request->query('hours', 6).'|'.$bucket);
        // Unfiltered fleet overview → fully rollup-backed (fast at any window). Filtered
        // queries can't use pre-aggregated rollups (arbitrary Port/Proto/Bytes predicates),
        // so they take the raw path below.
        if ($q === '') {
            return response()->json(Cache::remember($key, 90, fn () => $this->rollupOverview($from, $to)));
        }

        $payload = Cache::remember($key, 90, function () use ($from, $to, $q) {
            $base = fn () => FlowRecord::whereBetween('recorded_at', [$from, $to])
                ->when($q !== '', fn ($query) => $this->kql->filter($query, $q));

            // Top talkers in TWO steps so it stays fast at millions of rows:
            //  1) rank the top 25 (src,dst) pairs by bytes — this is covered by the
            //     (recorded_at,src_ip,dst_ip,bytes) index (index-only scan, no row lookups);
            //  2) hydrate ONLY those 25 pairs with app/ports/proto/device/last-seen.
            // The old single query MAX()'d those extra columns over the whole window, which
            // forced a clustered-row lookup for every one of ~140k rows → a 28s query.
            $ranked = $base()
                ->selectRaw('src_ip, dst_ip, SUM(bytes) as bytes, COUNT(*) as flows')
                ->groupBy('src_ip', 'dst_ip')->orderByDesc('bytes')->limit(25)->get();

            $detail = $ranked->isEmpty() ? collect() : $base()
                ->where(function ($w) use ($ranked) {
                    foreach ($ranked as $r) {
                        $w->orWhere(fn ($p) => $p->where('src_ip', $r->src_ip)->where('dst_ip', $r->dst_ip));
                    }
                })
                ->selectRaw('src_ip, dst_ip, MAX(app) as app, MAX(app_category) as app_category, MAX(protocol) as protocol, MAX(src_port) as src_port, MAX(dst_port) as dst_port, MAX(device_id) as device_id, MAX(recorded_at) as last_seen')
                ->groupBy('src_ip', 'dst_ip')->get()->keyBy(fn ($r) => $r->src_ip.'|'.$r->dst_ip);

            $talkers = $ranked->map(function ($r) use ($detail) {
                $d = $detail->get($r->src_ip.'|'.$r->dst_ip);

                return [
                    'src_ip' => $r->src_ip, 'dst_ip' => $r->dst_ip,
                    'bytes' => (int) $r->bytes, 'flows' => (int) $r->flows,
                    'app' => $d->app ?? null, 'app_category' => $d->app_category ?? null,
                    'protocol' => $d->protocol ?? null, 'src_port' => $d->src_port ?? null,
                    'dst_port' => $d->dst_port ?? null, 'device_id' => $d->device_id ?? null,
                    'last_seen' => $d->last_seen ?? null,
                ];
            })->values();
            // Apps breakdown is the heaviest raw pass (GROUP BY app over ~1M rows ≈ 8s).
            // Serve it from the hourly rollups for completed hours + raw only for the
            // current partial hour, UNFILTERED — rollups can't apply an arbitrary KQL
            // predicate, so a filtered query keeps the raw path.
            $apps = $q === ''
                ? $this->rollupApps($from, $to)
                : $base()->selectRaw('app, MAX(app_category) as app_category, SUM(bytes) as bytes, COUNT(*) as flows')
                    ->groupBy('app')->orderByDesc('bytes')->limit(12)->get();

            // total + flows in ONE pass; conversations driver-aware (COUNT(DISTINCT a,b) is
            // a single pass on MySQL; SQLite needs a DISTINCT subquery).
            $agg = $base()->selectRaw('SUM(bytes) as total, COUNT(*) as flows')->first();
            $conversations = DB::connection()->getDriverName() === 'sqlite'
                ? (int) DB::query()->fromSub($base()->select('src_ip', 'dst_ip')->distinct(), 't')->count()
                : (int) $base()->selectRaw('COUNT(DISTINCT src_ip, dst_ip) as c')->value('c');
            $totalBytes = (int) ($agg->total ?? 0);
            $topApp = $apps->first();

            return [
                'summary' => [
                    'total_bytes' => $totalBytes, 'flows' => (int) ($agg->flows ?? 0), 'conversations' => $conversations,
                    'top_app' => $topApp ? ['app' => $topApp->app, 'bytes' => (int) $topApp->bytes] : null,
                ],
                'talkers' => $talkers, 'talker_total' => $totalBytes, 'apps' => $apps,
            ];
        });

        return response()->json($payload);
    }

    /**
     * The full unfiltered overview payload, served from the hourly rollups (completed
     * hours) + a raw aggregate of the current partial hour — fast at ANY window because
     * rollup rows are orders of magnitude fewer than raw. No overlap: rollups cover
     * strictly before the current hour boundary, raw covers from it.
     *
     * @return array<string, mixed>
     */
    /**
     * Where the raw table has to take over from the rollups.
     *
     * Rollups are written after an hour closes, so the most recent closed hours can
     * have none yet. Reading raw only from the CURRENT hour left those hours in
     * neither source and their traffic vanished from the fleet overview — every hour,
     * until the rollup job caught up. Walking back from now while hours are uncovered
     * gives one boundary both sides can use, with no gap and no double counting.
     */
    private function rawBoundary(CarbonImmutable $from, CarbonImmutable $cut): CarbonImmutable
    {
        if ($from >= $cut) {
            return $cut;
        }

        $covered = FlowRollup::where('bucket', 'hour')
            ->where('bucket_start', '>=', $from)->where('bucket_start', '<', $cut)
            ->distinct()->pluck('bucket_start')
            ->map(fn ($b) => CarbonImmutable::parse($b)->startOfHour()->getTimestamp())
            ->flip();

        $boundary = $cut;
        for ($t = $cut->subHour(); $t >= $from; $t = $t->subHour()) {
            if ($covered->has($t->getTimestamp())) {
                break;
            }
            $boundary = $t;
        }

        return $boundary;
    }

    private function rollupOverview(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cut = CarbonImmutable::now()->startOfHour();
        // Raw takes over at the first hour the rollups do not cover, not merely at the
        // current hour — see rawBoundary().
        $rawFrom = $from->max($this->rawBoundary($from, $cut));
        $rollupOn = $from < $rawFrom;

        $rHour = fn () => FlowRollup::where('bucket', 'hour')
            ->where('bucket_start', '>=', $from)->where('bucket_start', '<', $rawFrom);
        $raw = fn () => FlowRecord::where('recorded_at', '>=', $rawFrom)->where('recorded_at', '<=', $to);

        // --- top talkers: rank top-100 from each source, merge, take 25, hydrate ---
        $merge = [];   // "src|dst" => [src,dst,bytes,flows]
        $add = function ($src, $dst, $bytes, $flows) use (&$merge) {
            $k = $src.'|'.$dst;
            $m = $merge[$k] ?? ['src' => $src, 'dst' => $dst, 'bytes' => 0, 'flows' => 0];
            $merge[$k] = ['src' => $src, 'dst' => $dst, 'bytes' => $m['bytes'] + (int) $bytes, 'flows' => $m['flows'] + (int) $flows];
        };
        if ($rollupOn) {
            $rHour()->where('group_type', 'talker')
                ->selectRaw('group_key as src, sub_key as dst, SUM(bytes) as bytes, SUM(flows) as flows')
                ->groupBy('group_key', 'sub_key')->orderByDesc('bytes')->limit(100)->get()
                ->each(fn ($r) => $add($r->src, $r->dst, $r->bytes, $r->flows));
        }
        $raw()->selectRaw('src_ip as src, dst_ip as dst, SUM(bytes) as bytes, COUNT(*) as flows')
            ->groupBy('src_ip', 'dst_ip')->orderByDesc('bytes')->limit(100)->get()
            ->each(fn ($r) => $add($r->src, $r->dst, $r->bytes, $r->flows));

        $top = collect($merge)->sortByDesc('bytes')->take(25)->values();
        // Hydrate app/ports/proto/device/last-seen for the top 25 from the current raw hour
        // (cheap; a talker whose traffic is entirely in older hours simply shows no ports).
        $detail = $top->isEmpty() ? collect() : $raw()
            ->where(function ($w) use ($top) {
                foreach ($top as $t) {
                    $w->orWhere(fn ($p) => $p->where('src_ip', $t['src'])->where('dst_ip', $t['dst']));
                }
            })
            ->selectRaw('src_ip, dst_ip, MAX(app) as app, MAX(app_category) as app_category, MAX(protocol) as protocol, MAX(src_port) as src_port, MAX(dst_port) as dst_port, MAX(device_id) as device_id, MAX(recorded_at) as last_seen')
            ->groupBy('src_ip', 'dst_ip')->get()->keyBy(fn ($r) => $r->src_ip.'|'.$r->dst_ip);

        $talkers = $top->map(function ($t) use ($detail) {
            $d = $detail->get($t['src'].'|'.$t['dst']);

            return [
                'src_ip' => $t['src'], 'dst_ip' => $t['dst'], 'bytes' => $t['bytes'], 'flows' => $t['flows'],
                'app' => $d->app ?? null, 'app_category' => $d->app_category ?? null,
                'protocol' => $d->protocol ?? null, 'src_port' => $d->src_port ?? null,
                'dst_port' => $d->dst_port ?? null, 'device_id' => $d->device_id ?? null,
                'last_seen' => $d->last_seen ?? null,
            ];
        });

        // --- totals + conversations (rollup + raw) ---
        $rBytes = $rollupOn ? (int) $rHour()->where('group_type', 'talker')->sum('bytes') : 0;
        $rFlows = $rollupOn ? (int) $rHour()->where('group_type', 'talker')->sum('flows') : 0;
        $wAgg = $raw()->selectRaw('SUM(bytes) as b, COUNT(*) as f')->first();
        $totalBytes = $rBytes + (int) ($wAgg->b ?? 0);
        $flows = $rFlows + (int) ($wAgg->f ?? 0);
        // conversations ≈ distinct pairs in rollups + distinct pairs in the raw hour (a
        // small overcount for pairs spanning the boundary — fine for a headline KPI).
        // Portable distinct-pair count via a DISTINCT subquery (COUNT(DISTINCT a,b) errors
        // on SQLite, so don't use it).
        $rConv = $rollupOn
            ? (int) DB::query()->fromSub($rHour()->where('group_type', 'talker')->select('group_key', 'sub_key')->distinct(), 't')->count()
            : 0;
        $wConv = (int) DB::query()->fromSub($raw()->select('src_ip', 'dst_ip')->distinct(), 'w')->count();

        $apps = $this->rollupApps($from, $to);
        $topApp = $apps->first();

        return [
            'summary' => [
                'total_bytes' => $totalBytes, 'flows' => $flows, 'conversations' => $rConv + $wConv,
                'top_app' => $topApp ? ['app' => $topApp->app, 'bytes' => (int) $topApp->bytes] : null,
            ],
            'talkers' => $talkers, 'talker_total' => $totalBytes, 'apps' => $apps,
        ];
    }

    /**
     * Per-app bytes for the window, from the hourly rollups (completed hours) merged with
     * a raw aggregate of the current partial hour. Fast (rollup rows are ~orders of
     * magnitude fewer than raw). Falls back to raw if there are no rollups yet.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rollupApps(CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        // Rollups below the boundary, raw at or above it — no gap, no overlap.
        $cut = CarbonImmutable::now()->startOfHour();
        $rawFrom = $from->max($this->rawBoundary($from, $cut));
        $merged = [];   // app => [bytes, flows, cat]

        if ($from < $rawFrom) {
            FlowRollup::where('group_type', 'app')->where('bucket', 'hour')
                ->where('bucket_start', '>=', $from)->where('bucket_start', '<', $rawFrom)
                ->selectRaw('group_key as app, MAX(app_category) as cat, SUM(bytes) as bytes, SUM(flows) as flows')
                ->groupBy('group_key')->get()
                ->each(function ($r) use (&$merged) {
                    $merged[$r->app] = ['bytes' => (int) $r->bytes, 'flows' => (int) $r->flows, 'cat' => $r->cat];
                });
        }

        FlowRecord::where('recorded_at', '>=', $rawFrom)->where('recorded_at', '<=', $to)
            ->selectRaw('app, MAX(app_category) as cat, SUM(bytes) as bytes, COUNT(*) as flows')
            ->groupBy('app')->get()
            ->each(function ($r) use (&$merged) {
                $m = $merged[$r->app] ?? ['bytes' => 0, 'flows' => 0, 'cat' => $r->cat];
                $merged[$r->app] = ['bytes' => $m['bytes'] + (int) $r->bytes, 'flows' => $m['flows'] + (int) $r->flows, 'cat' => $m['cat'] ?? $r->cat];
            });

        return collect($merged)->map(fn ($v, $app) => (object) ['app' => $app, 'app_category' => $v['cat'], 'bytes' => $v['bytes'], 'flows' => $v['flows']])
            ->sortByDesc('bytes')->take(12)->values();
    }

    /**
     * Traffic over time, bucketed, split by the top-N apps — the stacked-area hero of
     * the Flows page (bytes per bucket per app). Optional KQL filter. The time-bucket
     * expression is driver-aware (MySQL vs SQLite) per the dev/prod divergence rule.
     */
    public function timeseries(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        $q = (string) $request->query('q', '');
        $spanSec = max(1, $to->getTimestamp() - $from->getTimestamp());
        $step = max(60, (int) ceil($spanSec / 48));   // ~48 buckets across the window

        try {
            $this->kql->filter(FlowRecord::query(), $q === '' ? '' : $q);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Same caching rationale as overview — a coarse time-bucket key so it actually hits.
        $bucket = (int) floor(now()->timestamp / 60);
        $key = 'flows.timeseries.'.md5($q.'|'.$request->query('hours', 6).'|'.$bucket);

        // Unfiltered wide windows use the hourly rollups (the raw bucketed GROUP BY app
        // was ~11s over 1M rows); short/filtered windows keep the fine-grained raw build.
        $useRollup = $q === '' && $spanSec >= 7200;

        return response()->json(Cache::remember($key, 90, fn () => $useRollup
            ? $this->rollupTimeseries($from, $to)
            : $this->buildTimeseries($from, $to, $q, $step)));
    }

    /**
     * Hourly-resolution traffic-over-time from the rollups (completed hours) + the current
     * partial hour from raw as the final point. Series split by the window's top apps.
     *
     * @return array{series: array, step_seconds: int}
     */
    private function rollupTimeseries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cut = CarbonImmutable::now()->startOfHour();

        // Per-app hourly totals, assembled from whichever source actually holds each
        // hour. Totals are gathered BEFORE choosing the top apps, so an app is ranked
        // on everything it did in the window rather than on the rollups alone.
        $points = [];   // app => [ts_ms => bytes]
        $add = function (string $app, int $tsMs, int $bytes) use (&$points): void {
            $points[$app][$tsMs] = ($points[$app][$tsMs] ?? 0) + $bytes;
        };

        // Completed hours from the rollups.
        $covered = [];
        if ($from < $cut) {
            FlowRollup::where('group_type', 'app')->where('bucket', 'hour')
                ->where('bucket_start', '>=', $from)->where('bucket_start', '<', $cut)
                ->selectRaw('bucket_start, group_key as app, SUM(bytes) as bytes')
                ->groupBy('bucket_start', 'group_key')->get()
                ->each(function ($r) use ($add, &$covered) {
                    $ts = CarbonImmutable::parse($r->bucket_start)->getTimestamp();
                    $covered[$ts] = true;
                    $add((string) $r->app, $ts * 1000, (int) $r->bytes);
                });
        }

        // Any completed hour the rollups do NOT cover, read from raw.
        //
        // The rollup job runs after an hour closes, so for the minutes between the hour
        // ending and the job finishing, that hour belonged to neither branch: not a
        // completed rollup, not the current partial hour. Its traffic simply vanished
        // from the chart, every hour, until the job caught up.
        if ($from < $cut) {
            $driver = DB::connection()->getDriverName();
            $hourSql = $driver === 'sqlite'
                ? "(CAST(strftime('%s', recorded_at) AS INTEGER) / 3600) * 3600"
                : 'FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600';

            FlowRecord::where('recorded_at', '>=', $from)->where('recorded_at', '<', $cut)
                ->selectRaw("$hourSql as bucket, app, SUM(bytes) as bytes")
                ->groupBy('bucket', 'app')->get()
                ->each(function ($r) use ($add, $covered) {
                    $ts = (int) $r->bucket;
                    if (isset($covered[$ts])) {
                        return;   // the rollup already accounts for this hour
                    }
                    $add((string) $r->app, $ts * 1000, (int) $r->bytes);
                });
        }

        // The current, still-open hour, always from raw.
        FlowRecord::where('recorded_at', '>=', max($from, $cut))->where('recorded_at', '<=', $to)
            ->selectRaw('app, SUM(bytes) as bytes')->groupBy('app')->get()
            ->each(fn ($r) => $add((string) $r->app, $cut->getTimestamp() * 1000, (int) $r->bytes));

        // Rank on the assembled totals, then fold everything else into "Other".
        $totals = [];
        foreach ($points as $app => $byTs) {
            $totals[$app] = array_sum($byTs);
        }
        arsort($totals);
        $topApps = array_slice(array_keys($totals), 0, 6);

        $merged = [];
        foreach ($points as $app => $byTs) {
            $name = in_array($app, $topApps, true) ? $app : 'Other';
            foreach ($byTs as $ts => $bytes) {
                $merged[$name][$ts] = ($merged[$name][$ts] ?? 0) + $bytes;
            }
        }

        // Hourly bucket grid so every series aligns.
        $grid = [];
        for ($t = $from->startOfHour(); $t <= $to; $t = $t->addHour()) {
            $grid[] = $t->getTimestamp() * 1000;
        }
        $series = [];
        foreach ($merged as $name => $byTs) {
            $series[] = ['name' => $name, 'data' => array_map(fn ($ms) => [$ms, $byTs[$ms] ?? 0], $grid)];
        }

        return ['series' => $series, 'step_seconds' => 3600];
    }

    /** @return array{series: array, step_seconds: int} */
    private function buildTimeseries(CarbonImmutable $from, CarbonImmutable $to, string $q, int $step): array
    {
        $base = FlowRecord::whereBetween('recorded_at', [$from, $to])
            ->when($q !== '', fn ($query) => $this->kql->filter($query, $q));

        $driver = DB::connection()->getDriverName();
        $bucketSql = $driver === 'sqlite'
            ? "(CAST(strftime('%s', recorded_at) AS INTEGER) / $step) * $step"
            : "FLOOR(UNIX_TIMESTAMP(recorded_at) / $step) * $step";

        // Top apps in the window drive the series (the rest fold into "Other").
        $topApps = (clone $base)->selectRaw('app, SUM(bytes) as b')->groupBy('app')
            ->orderByDesc('b')->limit(6)->pluck('app')->filter()->values();

        $rows = (clone $base)
            ->selectRaw("$bucketSql as bucket, app, SUM(bytes) as bytes")
            ->groupByRaw("$bucketSql, app")->get();

        // Assemble per-app series over the bucket grid; non-top apps → "Other".
        $buckets = [];
        for ($t = intdiv($from->getTimestamp(), $step) * $step; $t <= $to->getTimestamp(); $t += $step) {
            $buckets[] = $t;
        }
        $keys = $topApps->all();
        $seriesMap = [];
        foreach (array_merge($keys, ['Other']) as $name) {
            $seriesMap[$name] = array_fill_keys($buckets, 0);
        }
        foreach ($rows as $r) {
            $name = in_array($r->app, $keys, true) ? $r->app : 'Other';
            $b = (int) $r->bucket;
            if (isset($seriesMap[$name][$b])) {
                $seriesMap[$name][$b] += (int) $r->bytes;
            }
        }

        $series = [];
        foreach ($seriesMap as $name => $points) {
            if ($name === 'Other' && array_sum($points) === 0) {
                continue;
            }
            $series[] = ['name' => $name, 'data' => array_map(fn ($t) => [$t * 1000, $points[$t]], $buckets)];
        }

        return ['series' => $series, 'step_seconds' => $step];
    }

    /** Autocomplete values for the KQL bar: distinct apps/devices/protocols, or top IPs. */
    public function values(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        $field = strtolower((string) $request->query('field', 'app'));
        $term = (string) $request->query('term', '');
        $base = FlowRecord::whereBetween('recorded_at', [$from, $to]);

        if ($field === 'device') {
            $ids = (clone $base)->whereNotNull('device_id')->distinct()->pluck('device_id');
            $values = \App\Models\Device::whereIn('id', $ids)
                ->when($term !== '', fn ($qq) => $qq->where('name', 'like', '%'.$term.'%'))
                ->orderBy('name')->limit(20)->pluck('name');

            return response()->json(['values' => $values]);
        }

        if (in_array($field, ['srcip', 'dstip', 'ip'], true)) {
            $col = $field === 'dstip' ? 'dst_ip' : 'src_ip';
            $values = (clone $base)->when($term !== '', fn ($qq) => $qq->where($col, 'like', $term.'%'))
                ->selectRaw("$col as v, SUM(bytes) as b")->groupBy($col)->orderByDesc('b')->limit(20)->pluck('v');

            return response()->json(['values' => $values]);
        }

        $col = ['app' => 'app', 'protocol' => 'protocol', 'direction' => 'direction', 'category' => 'app_category'][$field] ?? 'app';
        $values = (clone $base)->whereNotNull($col)
            ->when($term !== '', fn ($qq) => $qq->where($col, 'like', '%'.$term.'%'))
            ->distinct()->orderBy($col)->limit(20)->pluck($col);

        return response()->json(['values' => $values]);
    }

    /** Reverse-DNS a batch of IPs (cached) for the Flows UI to show hostnames. */
    public function resolve(Request $request): JsonResponse
    {
        $ips = array_slice(array_filter(array_map('trim', explode(',', (string) $request->query('ips', '')))), 0, 100);
        $names = [];
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $names[$ip] = Cache::remember("ptr:$ip", 3600, function () use ($ip) {
                $host = @gethostbyaddr($ip);

                return ($host && $host !== $ip) ? $host : null;
            });
        }

        return response()->json(['names' => $names]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(Request $request): array
    {
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to')) : CarbonImmutable::now();
        $from = $request->query('from')
            ? CarbonImmutable::parse($request->query('from'))
            : $to->subHours(max(1, min(744, (int) $request->query('hours', 6))));  // up to 7d (raw retention)

        return [$from, $to];
    }
}
