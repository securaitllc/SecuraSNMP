<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use Illuminate\Http\JsonResponse;

/**
 * Open baseline-deviation anomalies, worst (highest |z|) first, each with a short
 * recent series for the sparkline. A non-paging feed — deliberately separate from the
 * alarm stream.
 */
class AnomalyController extends Controller
{
    private const METRIC = ['throughput' => 'Throughput', 'discards' => 'Discards', 'latency' => 'Latency'];

    private const SPARK_POINTS = 14;

    public function index(): JsonResponse
    {
        $anoms = Anomaly::open()->orderByRaw('ABS(z_score) DESC')->limit(200)->get();

        $ifaces = DeviceInterface::with('device.site')
            ->whereIn('id', $anoms->where('entity_type', 'interface')->pluck('entity_id')->unique())
            ->get()->keyBy('id');
        $circuits = Circuit::with('site')
            ->whereIn('id', $anoms->where('entity_type', 'circuit')->pluck('entity_id')->unique())
            ->get()->keyBy('id');

        $data = $anoms->map(function (Anomaly $a) use ($ifaces, $circuits) {
            if ($a->entity_type === 'interface') {
                $if = $ifaces->get($a->entity_id);
                $entity = $if?->if_name ?? "interface {$a->entity_id}";
                $sub = $if?->device?->name;
                $site = $if?->device?->site?->name;
                $route = $if?->device_id ? "/devices/{$if->device_id}" : null;
            } else {
                $c = $circuits->get($a->entity_id);
                $entity = $c?->circuit_id ?? "circuit {$a->entity_id}";
                $sub = $c?->isp_name;
                $site = $c?->site?->name;
                $route = $c ? '/circuits?q='.urlencode((string) $c->circuit_id) : null;
            }

            return [
                'id' => $a->id,
                'entity_type' => $a->entity_type,
                'entity' => $entity,
                'sub' => $sub,
                'site_name' => $site,
                'metric' => self::METRIC[$a->metric] ?? $a->metric,
                'metric_key' => $a->metric,
                'direction' => $a->direction,
                'baseline' => $a->baseline,
                'observed' => $a->observed,
                'z_score' => $a->z_score,
                'series' => $this->series($a),
                'detected_at' => $a->detected_at,
                'last_seen_at' => $a->last_seen_at,
                'route' => $route,
            ];
        });

        return response()->json([
            'data' => $data->values(),
            'summary' => [
                'open' => $anoms->count(),
                'by_metric' => [
                    'throughput' => $anoms->where('metric', 'throughput')->count(),
                    'discards' => $anoms->where('metric', 'discards')->count(),
                    'latency' => $anoms->where('metric', 'latency')->count(),
                ],
                'by_type' => [
                    'interface' => $anoms->where('entity_type', 'interface')->count(),
                    'circuit' => $anoms->where('entity_type', 'circuit')->count(),
                ],
                'worst_z' => round((float) $anoms->max(fn (Anomaly $a) => abs($a->z_score)), 1),
                'oldest_at' => optional($anoms->min('detected_at'))->toIso8601String(),
            ],
        ]);
    }

    /** The last SPARK_POINTS samples of the anomaly's metric, oldest→newest. */
    private function series(Anomaly $a): array
    {
        if ($a->entity_type === 'circuit') {
            return CircuitMetricHistory::where('circuit_id', $a->entity_id)
                ->whereNotNull('response_time_ms')->latest('recorded_at')->limit(self::SPARK_POINTS)
                ->pluck('response_time_ms')->reverse()->map(fn ($v) => (float) $v)->values()->all();
        }

        $rows = InterfaceMetricHistory::where('device_interface_id', $a->entity_id)
            ->latest('recorded_at')->limit(self::SPARK_POINTS)
            ->get(['in_octets_delta', 'out_octets_delta', 'in_discards_delta', 'out_discards_delta']);

        return $rows->reverse()->map(fn ($r) => $a->metric === 'discards'
            ? (float) ($r->in_discards_delta + $r->out_discards_delta)
            : (float) max($r->in_octets_delta, $r->out_octets_delta))->values()->all();
    }
}
