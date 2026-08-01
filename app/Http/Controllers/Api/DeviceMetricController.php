<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceMetricHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceMetricController extends Controller
{
    private const RANGE_HOURS = [
        '1h' => 1,
        '6h' => 6,
        '24h' => 24,
        '7d' => 168,
        '30d' => 720,
    ];

    public function index(Request $request): JsonResponse
    {
        // device_id is REQUIRED. Without it this loaded every device's history for
        // the range — on the live fleet that 500s outright. Nothing legitimately
        // wants that shape: callers either graph one device or use summary(), which
        // is windowed and point-capped for exactly this reason.
        $request->validate(['device_id' => ['required', 'integer', 'exists:devices,id']]);

        $hours = self::RANGE_HOURS[$request->query('range', '24h')] ?? self::RANGE_HOURS['24h'];

        $metrics = DeviceMetricHistory::query()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->where('device_id', $request->query('device_id'))
            ->orderBy('recorded_at')
            ->get();

        return response()->json($metrics);
    }

    /**
     * Latest response time + a short sparkline for EVERY device, in one query —
     * so the devices list makes a single request instead of one per device (which
     * hammered the server on large fleets).
     */
    public function summary(): JsonResponse
    {
        // The sparkline shows ~40 recent points per device at a 60s ping cadence,
        // so only the last hour is needed. Querying 6 hours across the whole fleet
        // loaded ~100k rows into memory and OOM'd once the fleet grew past ~200
        // devices — cap the window so this scales with the device count.
        // toBase(): return plain rows, not Eloquent models — hydrating ~16k models
        // just to read two columns was most of the cost. Pairs with the
        // recorded_at-led covering index for an index-only scan.
        $rows = DeviceMetricHistory::where('recorded_at', '>=', now()->subMinutes(60))
            ->orderBy('recorded_at')
            ->toBase()
            ->get(['device_id', 'response_time_ms']);

        $byDevice = [];
        foreach ($rows as $row) {
            $byDevice[$row->device_id][] = $row->response_time_ms === null ? null : (float) $row->response_time_ms;
        }

        $out = [];
        foreach ($byDevice as $id => $points) {
            // Cap the sparkline so a chatty device doesn't bloat the payload.
            $points = array_slice($points, -40);
            $out[$id] = ['points' => $points, 'latest' => end($points)];
        }

        return response()->json($out);
    }
}
