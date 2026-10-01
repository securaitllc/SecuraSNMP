<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;

class NotificationLogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            NotificationLog::with('channel:id,name,type')
                ->latest()
                ->limit(100)
                ->get()
        );
    }
}
