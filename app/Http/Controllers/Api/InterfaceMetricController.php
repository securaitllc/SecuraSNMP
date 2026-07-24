<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InterfaceMetricHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterfaceMetricController extends Controller
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

        $metrics = InterfaceMetricHistory::query()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->when($request->query('interface_id'), fn ($query, $id) => $query->where('device_interface_id', $id))
            ->when($request->query('device_id'), fn ($query, $deviceId) => $query->whereHas(
                'deviceInterface',
                fn ($q) => $q->where('device_id', $deviceId)
            ))
            ->orderBy('recorded_at')
            ->get();

        return response()->json($metrics);
    }
}
