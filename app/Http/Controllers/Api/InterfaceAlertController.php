<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterfaceAlertController extends Controller
{
    public function index(DeviceInterface $interface): JsonResponse
    {
        return response()->json($interface->alerts()->orderByDesc('started_at')->get());
    }

    /** Acknowledge an interface-down alert (marks it seen; stays open). */
    public function acknowledge(Request $request, InterfaceAlert $alert): JsonResponse
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
     * Manually clear an interface-down alert with a resolution note. Reuses
     * ended_at as the close time; the poller only re-opens on a real up->down
     * flap, so a cleared alert does not resurrect on the next poll.
     */
    public function clear(Request $request, InterfaceAlert $alert): JsonResponse
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
