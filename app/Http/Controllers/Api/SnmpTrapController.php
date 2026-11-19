<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class SnmpTrapController extends Controller
{
    public function index(Device $device): JsonResponse
    {
        return response()->json(
            $device->snmpTraps()->orderByDesc('received_at')->limit(100)->get()
        );
    }
}
