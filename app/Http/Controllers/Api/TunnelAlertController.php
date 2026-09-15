<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TunnelAlertController extends Controller
{
    public function index(Tunnel $tunnel): JsonResponse
    {
        return response()->json($tunnel->alerts()->orderByDesc('started_at')->get());
    }

    /** Acknowledge a tunnel-down alert (marks it seen; stays open). */
    public function acknowledge(Request $request, TunnelAlert $alert): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'ack_note' => $data['note'] ?? null,
        ]);

        return response()->json($alert->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }

    /**
     * Manually clear a tunnel-down alert with a note (e.g. the peer was removed
     * from the orchestrator). Reuses ended_at; the verifier only re-opens on a
     * real up->down flap, so a cleared alert does not resurrect.
     */
    public function clear(Request $request, TunnelAlert $alert): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $alert->update([
            'ended_at' => now(),
            'cleared_by' => $request->user()->id,
            'clear_note' => $data['note'] ?? null,
            'cleared_manually' => true,
        ]);

        return response()->json($alert->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }
}
