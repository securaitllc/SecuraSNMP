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
use App\Services\AnomalyCorrelation;
use App\Services\CarrierCorrelation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Open baseline-deviation anomalies, worst (highest |z|) first, each with a short
 * recent series for the sparkline. A non-paging feed — deliberately separate from the
 * alarm stream.
 */
class AnomalyController extends Controller
{
    private const METRIC = ['throughput' => 'Throughput', 'discards' => 'Discards', 'errors' => 'Errors', 'latency' => 'Latency', 'loss' => 'Packet loss', 'cpu' => 'CPU', 'memory' => 'Memory', 'temperature' => 'Temperature'];

    private const SPARK_POINTS = 14;

    public function index(Request $request): JsonResponse
    {
        $q = Anomaly::open();

        // Scoped views for the device / circuit detail pages: a device asks for its own
        // anomalies AND its interfaces' (that's where throughput/discard deviations live);
        // a circuit asks for just its own. No scope = the full dashboard feed.
        if ($deviceId = (int) $request->query('device')) {
            $ifaceIds = DeviceInterface::where('device_id', $deviceId)->pluck('id')->all();
            $q->where(function ($w) use ($deviceId, $ifaceIds) {
                $w->where(fn ($x) => $x->where('entity_type', 'device')->where('entity_id', $deviceId))
                    ->orWhere(fn ($x) => $x->where('entity_type', 'interface')->whereIn('entity_id', $ifaceIds));
            });
        } elseif ($circuitId = (int) $request->query('circuit')) {
            $q->where('entity_type', 'circuit')->where('entity_id', $circuitId);
        }

        $scoped = $request->query('device') || $request->query('circuit');

        $anoms = $q->orderByRaw('ABS(z_score) DESC')->limit(200)->get();

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
            // Each route deep-links to WHERE the problem is: an anomalies section on the
            // device page (scrolled + the exact row highlighted via ?focusAnomaly), or the
            // circuit list opened on that circuit's expanded anomalies.
            $isp = null;
            if ($a->entity_type === 'interface') {
                $if = $ifaces->get($a->entity_id);
                $entity = $if?->if_name ?? "interface {$a->entity_id}";
                $sub = $if?->device?->name;
                $site = $if?->device?->site?->name;
                $route = $if?->device_id ? "/devices/{$if->device_id}?focusAnomaly={$a->id}" : null;
            } elseif ($a->entity_type === 'device') {
                $d = $devices->get($a->entity_id);
                $entity = $d?->name ?? "device {$a->entity_id}";
                $sub = $d?->model;
                $site = $d?->site?->name;
                $route = $d ? "/devices/{$a->entity_id}?focusAnomaly={$a->id}" : null;
            } else {
                $c = $circuits->get($a->entity_id);
                $entity = $c?->circuit_id ?? "circuit {$a->entity_id}";
                $sub = $c?->isp_name;
                $site = $c?->site?->name;
                $route = $c ? '/circuits?q='.urlencode((string) $c->circuit_id)."&focusAnomaly={$a->id}" : null;

                // An ISP ticket, reachable from the finding that justifies it.
                //
                // Packet loss or latency on a circuit that never drops leaves nothing to
                // log a ticket against: the alarm surfaces carry the ISP fields, and a
                // circuit that is UP has no alarm. The operator raised a ticket with the
                // carrier and had nowhere in this app to record it.
                //
                // These are the CIRCUIT-level endpoints on purpose. The alert-level ones
                // (/ticket, /dispatch) call openAlert(), which CREATES an outage row when
                // none is open — that would invent a circuit outage to hang a ticket on,
                // and a degrade is not an outage.
                $isp = $c ? [
                    'circuit_id' => $c->id,
                    'circuit_code' => $c->circuit_id,
                    'isp_name' => $c->isp_name,
                    'support_phone' => $c->support_phone,
                    'isp_ticket' => $c->isp_ticket,
                    'dispatch_at' => optional($c->dispatch_at)->toIso8601String(),
                    'dispatch_end_at' => optional($c->dispatch_end_at)->toIso8601String(),
                    'dispatch_note' => $c->dispatch_note,
                    'isp_ticket_url' => "/api/circuits/{$c->id}/isp-ticket",
                    'dispatch_url' => "/api/circuits/{$c->id}/isp-dispatch",
                ] : null;
            }

            return [
                'id' => $a->id,
                'entity_type' => $a->entity_type,
                // Circuit findings only; null everywhere else.
                'isp' => $isp ?? null,
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
            // Carriers losing packets on several circuits at once. Only on the unscoped
            // feed: a single device or circuit cannot show a fleet pattern, and asking
            // for one on every detail-page load would be a full circuit scan for nothing.
            'correlations' => $scoped ? [] : app(CarrierCorrelation::class)->findings(),
            // Entities that deviated TOGETHER, in the same way, at the same moment.
            // Fifty latency anomalies from one egress change is one event; reading it
            // as fifty is what buries the one that matters.
            'shared_events' => $scoped ? [] : app(AnomalyCorrelation::class)->findings($anoms),
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
