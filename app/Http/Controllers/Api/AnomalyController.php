<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\Device;
use App\Models\DeviceHealthHistory;
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
    private const METRIC = ['throughput' => 'Throughput', 'discards' => 'Discards', 'errors' => 'Errors', 'latency' => 'Latency', 'loss' => 'Packet loss', 'cpu' => 'CPU', 'memory' => 'Memory', 'temperature' => 'Temperature'];

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
        $devices = Device::with('site')
            ->whereIn('id', $anoms->where('entity_type', 'device')->pluck('entity_id')->unique())
            ->get()->keyBy('id');

        $data = $anoms->map(function (Anomaly $a) use ($ifaces, $circuits, $devices) {
            if ($a->entity_type === 'interface') {
                $if = $ifaces->get($a->entity_id);
                $entity = $if?->if_name ?? "interface {$a->entity_id}";
                $sub = $if?->device?->name;
                $site = $if?->device?->site?->name;
                $route = $if?->device_id ? "/devices/{$if->device_id}" : null;
            } elseif ($a->entity_type === 'device') {
                $d = $devices->get($a->entity_id);
                $entity = $d?->name ?? "device {$a->entity_id}";
                $sub = $d?->model;
                $site = $d?->site?->name;
                $route = $d ? "/devices/{$a->entity_id}" : null;
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
                    'errors' => $anoms->where('metric', 'errors')->count(),
                    'latency' => $anoms->where('metric', 'latency')->count(),
                    'loss' => $anoms->where('metric', 'loss')->count(),
                    'cpu' => $anoms->where('metric', 'cpu')->count(),
                    'memory' => $anoms->where('metric', 'memory')->count(),
                    'temperature' => $anoms->where('metric', 'temperature')->count(),
                ],
                'by_type' => [
                    'interface' => $anoms->where('entity_type', 'interface')->count(),
                    'circuit' => $anoms->where('entity_type', 'circuit')->count(),
                    'device' => $anoms->where('entity_type', 'device')->count(),
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
            $col = $a->metric === 'loss' ? 'loss_pct' : 'response_time_ms';

            return CircuitMetricHistory::where('circuit_id', $a->entity_id)
                ->whereNotNull($col)->latest('recorded_at')->limit(self::SPARK_POINTS)
                ->pluck($col)->reverse()->map(fn ($v) => (float) $v)->values()->all();
        }

        if ($a->entity_type === 'device') {
            $col = ['cpu' => 'cpu_pct', 'memory' => 'mem_pct', 'temperature' => 'temperature_c'][$a->metric] ?? 'cpu_pct';

            return DeviceHealthHistory::where('device_id', $a->entity_id)
                ->whereNotNull($col)->latest('recorded_at')->limit(self::SPARK_POINTS)
                ->pluck($col)->reverse()->map(fn ($v) => (float) $v)->values()->all();
        }

        $rows = InterfaceMetricHistory::where('device_interface_id', $a->entity_id)
            ->latest('recorded_at')->limit(self::SPARK_POINTS)
            ->get(['in_octets_delta', 'out_octets_delta', 'in_discards_delta', 'out_discards_delta', 'in_errors_delta', 'out_errors_delta']);

        return $rows->reverse()->map(fn ($r) => match ($a->metric) {
            'discards' => (float) ($r->in_discards_delta + $r->out_discards_delta),
            'errors' => (float) ($r->in_errors_delta + $r->out_errors_delta),
            default => (float) max($r->in_octets_delta, $r->out_octets_delta),
        })->values()->all();
    }
}
