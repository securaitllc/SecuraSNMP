<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class NextHopAlertController extends Controller
{
    public function index(Device $device): JsonResponse
    {
        return response()->json($device->nextHopAlerts()->orderByDesc('started_at')->get());
    }
}
