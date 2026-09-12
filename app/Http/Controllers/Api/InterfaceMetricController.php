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
        // A scope is REQUIRED. Unscoped, this loaded every interface's history for
        // the range — a single switch here carries 125 interfaces, so fleet-wide it
        // 500s on live data. Every caller already graphs one interface or one
        // device's interfaces.
        $request->validate([
            'interface_id' => ['required_without:device_id', 'integer', 'exists:device_interfaces,id'],
            'device_id' => ['required_without:interface_id', 'integer', 'exists:devices,id'],
        ]);

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

        // Cap points per interface so a densely-polled port (or a whole-device
        // request) can't ship a series big enough to freeze the chart.
        return response()->json(\App\Support\MetricDownsampler::decimate($metrics, 'device_interface_id'));
    }
}
