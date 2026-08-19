<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FlowRecord;
use App\Services\Flow\KqlFlowQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
