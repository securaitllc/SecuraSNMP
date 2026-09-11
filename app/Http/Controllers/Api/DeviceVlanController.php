<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class DeviceVlanController extends Controller
{
    public function index(Device $device): JsonResponse
    {
        // Only VLANs the switch currently reports — a VLAN that vanished (or an old
        // internal-index row superseded by its real 802.1Q tag) is marked inactive
        // and must not leak to consumers as if it were live.
        return response()->json(
            $device->vlans()->where('status', 'active')->orderBy('vlan_id')->get()
        );
    }
}
