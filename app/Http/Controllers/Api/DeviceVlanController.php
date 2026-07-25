<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class DeviceVlanController extends Controller
{
    public function index(Device $device): JsonResponse
    {
        return response()->json($device->vlans()->orderBy('vlan_id')->get());
    }
}
