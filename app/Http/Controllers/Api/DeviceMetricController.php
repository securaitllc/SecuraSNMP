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
        $hours = self::RANGE_HOURS[$request->query('range', '24h')] ?? self::RANGE_HOURS['24h'];

        $metrics = DeviceMetricHistory::query()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->when($request->query('device_id'), fn ($query, $id) => $query->where('device_id', $id))
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
        $rows = DeviceMetricHistory::where('recorded_at', '>=', now()->subMinutes(60))
            ->orderBy('recorded_at')
            ->get(['device_id', 'response_time_ms']);

        $byDevice = [];
        foreach ($rows as $row) {
            $byDevice[$row->device_id][] = $row->response_time_ms;
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
