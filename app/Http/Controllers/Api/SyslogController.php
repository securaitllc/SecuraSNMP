<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyslogMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyslogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(500, max(1, (int) $request->query('limit', 200)));

        $messages = SyslogMessage::query()
            ->with('device:id,name')
            ->when($request->query('device_id'), fn ($q, $id) => $q->where('device_id', $id))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', '<=', (int) $request->query('severity')))
            ->when($request->query('q'), fn ($q, $term) => $q->where('message', 'like', '%'.$term.'%'))
            ->latest('received_at')
            ->limit($limit)
            ->get();

        return response()->json($messages);
    }
}
