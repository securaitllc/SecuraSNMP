<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyslogMessage;
use App\Support\Sql;
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
            // INJ-01: % / _ matched literally — a bare % was a full scan of the biggest table.
            ->when($request->query('q'), fn ($q, $term) => Sql::whereLike($q, 'message', '%'.Sql::escapeLike((string) $term).'%'))
            ->latest('received_at')
            ->limit($limit)
            ->get();

        return response()->json($messages);
    }
}
