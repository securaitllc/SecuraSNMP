<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TunnelMetricHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TunnelMetricController extends Controller
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

        $metrics = TunnelMetricHistory::query()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->when($request->query('tunnel_id'), fn ($query, $id) => $query->where('tunnel_id', $id))
            ->when($request->query('device_id'), fn ($query, $deviceId) => $query->whereHas(
                'tunnel',
                fn ($q) => $q->where('device_id', $deviceId)
            ))
            ->orderBy('recorded_at')
            ->get();

        return response()->json($metrics);
    }
}
