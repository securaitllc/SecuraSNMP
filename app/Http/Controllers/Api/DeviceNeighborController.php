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
        $neighbors = $device->lldpNeighbors()
            ->orderBy('local_port')
            ->get([
                'id', 'local_port', 'remote_sysname', 'remote_port', 'neighbor_type',
                'remote_mgmt_addr', 'remote_mac', 'extension', 'endpoint_model',
                'remote_device_id', 'last_seen_at',
            ]);

        return response()->json($neighbors);
    }
}
