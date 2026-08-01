<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceAlarm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAlarmController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $alarms = DeviceAlarm::query()
            ->with(['acknowledgedBy:id,name', 'clearedBy:id,name'])
            ->when($request->query('device_id'), fn ($query, $deviceId) => $query->where('device_id', $deviceId))
            ->when($request->boolean('active'), fn ($query) => $query->whereNull('cleared_at'))
            ->orderByDesc('first_seen_at')
            ->get();

        // Serialize the FK columns as ids and expose the actor names under
        // explicit keys, so the loaded relations don't collide with the
        // acknowledged_by / cleared_by integer columns on toArray().
        return response()->json($alarms->map(fn (DeviceAlarm $a) => [
            ...$a->toArray(),
            'acknowledged_by' => $a->acknowledged_by,
            'acknowledged_by_name' => optional($a->acknowledgedBy)->name,
            'cleared_by' => $a->cleared_by,
            'cleared_by_name' => optional($a->clearedBy)->name,
        ]));
    }

    /** Acknowledge an alarm with an investigation note. */
    public function acknowledge(Request $request, DeviceAlarm $alarm): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $alarm->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'ack_note' => $data['note'] ?? null,
        ]);

        return response()->json($alarm->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }

    /** Manually clear an alarm with a resolution note. It will not reopen until
     *  the appliance clears the condition and it re-occurs (a flap). */
    /**
     * Clear many alarms at once.
     *
     * Link-quality breaches arrive in floods — a single orchestrator or cloud
     * latency event raises one alarm per tunnel per appliance, and on the reference
     * fleet that was every open alarm at once. Clearing those one at a time is not
     * work, it is attrition, and an operator doing it a hundred times will
     * eventually clear a real outage by reflex.
     *
     * Ids are explicit rather than a filter: the caller clears exactly the rows it
     * showed the operator, so a race with the poller cannot widen the blast radius
     * between rendering and confirming.
     *
     * Manual clears still respect the no-resurrect rule — an alarm the appliance is
     * still reporting stays cleared until it genuinely flaps.
     */
    public function bulkClear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $cleared = DeviceAlarm::whereIn('id', $data['ids'])
            ->whereNull('cleared_at')
            ->update([
                'cleared_at' => now(),
                'cleared_by' => $request->user()->id,
                'clear_note' => $data['note'] ?? null,
                'cleared_manually' => true,
            ]);

        return response()->json([
            'cleared' => $cleared,
            'requested' => count($data['ids']),
        ]);
    }

    public function clear(Request $request, DeviceAlarm $alarm): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $alarm->update([
            'cleared_at' => now(),
            'cleared_by' => $request->user()->id,
            'clear_note' => $data['note'] ?? null,
            'cleared_manually' => true,
        ]);

        return response()->json($alarm->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }
}
