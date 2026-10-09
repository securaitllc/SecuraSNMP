<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * What is connected to each port of a switch, as advertised over LLDP.
 *
 * This existed only inside the topology payload, which meant an operator looking at
 * a device had to leave it and find the site map to answer "what is on port 27?".
 * The device panel is where that question actually gets asked.
 */
class DeviceNeighborController extends Controller
{
    public function index(Device $device): JsonResponse
    {
        // Endpoints still reported first; ones that have disconnected follow, most
        // recently seen first — that is the order the question gets asked in ("the
        // port is down, what was on it?").
        $neighbors = $device->lldpNeighbors()
            ->orderByRaw('CASE WHEN absent_since IS NULL THEN 0 ELSE 1 END')
            ->orderBy('local_port')
            ->orderByDesc('last_seen_at')
            ->get([
                'id', 'local_port', 'remote_sysname', 'remote_port', 'neighbor_type',
                'remote_mgmt_addr', 'remote_mac', 'extension', 'endpoint_model',
                'remote_device_id', 'last_seen_at', 'absent_since',
            ]);

        return response()->json($neighbors);
    }
}
