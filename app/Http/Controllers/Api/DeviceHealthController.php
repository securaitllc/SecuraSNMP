<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceHealthController extends Controller
{
    /** CPU/memory/temperature trend for a device over a time range. */
    public function history(Request $request, Device $device): JsonResponse
    {
        $hours = match ($request->query('range')) {
            '1h' => 1,
            '24h' => 24,
            '7d' => 168,
            default => 6,
        };

        $rows = $device->healthHistory()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'cpu_pct', 'mem_pct', 'temperature_c']);

        return response()->json($rows);
    }
}
