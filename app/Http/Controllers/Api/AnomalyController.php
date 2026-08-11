<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\DeviceInterface;
use Illuminate\Http\JsonResponse;

/**
 * Open baseline-deviation anomalies, worst (highest |z|) first, with the entity and
 * a route to it. A non-paging feed — deliberately separate from the alarm stream.
 */
class AnomalyController extends Controller
{
    private const METRIC = ['throughput' => 'Throughput', 'discards' => 'Discards', 'latency' => 'Latency'];

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
                'detected_at' => $a->detected_at,
                'last_seen_at' => $a->last_seen_at,
                'route' => $route,
            ];
        });

        return response()->json(['data' => $data->values(), 'open' => $anoms->count()]);
    }
}
