<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterfaceAlertController extends Controller
{
    /**
     * Every interface alert for a device, cleared ones included.
     *
     * The device page loads interfaces with only their OPEN alerts, so clearing one
     * made it vanish with no record of the ticket, who cleared it or why. The data
     * was always being written — nothing displayed it. Device alarms have had an
     * Alarm History card all along; interface alerts had no equivalent.
     *
     * Capped because a flapping port on a busy switch accumulates alerts quickly and
     * this is a history panel, not an export.
     */
    public function forDevice(Device $device): JsonResponse
    {
        $alerts = InterfaceAlert::query()
            ->whereHas('deviceInterface', fn ($q) => $q->where('device_id', $device->id))
            ->with(['deviceInterface:id,if_name', 'acknowledgedBy:id,name', 'clearedBy:id,name'])
            ->orderByDesc('started_at')
            ->limit(200)
            ->get();

        return response()->json($alerts);
    }

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
