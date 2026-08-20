<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FlowRecord;
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

        // Cache 20s: at ~140k flows/hour these aggregates scan ~1M+ rows, so an uncached
        // hit is expensive and concurrent/auto-refresh loads would pile up on the DB. A
        // short TTL keeps it live-ish while coalescing repeats and protecting the app tier.
        $key = 'flows.overview.'.md5($q.'|'.$from->timestamp.'|'.$to->timestamp);
        $payload = Cache::remember($key, 20, function () use ($from, $to, $q) {
            $base = fn () => FlowRecord::whereBetween('recorded_at', [$from, $to])
                ->when($q !== '', fn ($query) => $this->kql->filter($query, $q));

            $talkers = $base()
                ->selectRaw('src_ip, dst_ip, MAX(app) as app, MAX(app_category) as app_category, MAX(protocol) as protocol, MAX(src_port) as src_port, MAX(dst_port) as dst_port, MAX(device_id) as device_id, MAX(recorded_at) as last_seen, SUM(bytes) as bytes, SUM(packets) as packets, COUNT(*) as flows')
                ->groupBy('src_ip', 'dst_ip')->orderByDesc('bytes')->limit(25)->get();
            $apps = $base()
                ->selectRaw('app, MAX(app_category) as app_category, SUM(bytes) as bytes, COUNT(*) as flows')
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

        // Same caching rationale as overview — a bucketed group-over-1M-rows is heavy.
        $key = 'flows.timeseries.'.md5($q.'|'.$from->timestamp.'|'.$to->timestamp);

        return response()->json(Cache::remember($key, 20, fn () => $this->buildTimeseries($from, $to, $q, $step)));
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
            : $to->subHours(max(1, min(48, (int) $request->query('hours', 6))));

        return [$from, $to];
    }
}
