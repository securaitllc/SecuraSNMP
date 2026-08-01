<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TunnelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tunnels = Tunnel::query()
            ->when($request->query('device_id'), fn ($query, $deviceId) => $query->where('device_id', $deviceId))
            // ?down=1 → only tunnels currently down (a hub can have hundreds up).
            ->when($request->boolean('down'), fn ($query) => $query->where('status', 'down'))
            // The open alert (if any) so the device page can act on it.
            ->with(['alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->orderBy('tunnel_name')
            ->get();

        return response()->json($tunnels);
    }
}
