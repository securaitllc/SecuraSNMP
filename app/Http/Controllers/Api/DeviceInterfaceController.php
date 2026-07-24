<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceInterfaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $interfaces = DeviceInterface::query()
            ->when($request->query('device_id'), fn ($query, $deviceId) => $query->where('device_id', $deviceId))
            // The open interface-down alert (if any) so the device page can show and
            // act on it — the interface index is the only per-device interface feed.
            ->with(['alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->orderBy('if_index')
            ->get();

        return response()->json($interfaces);
    }

    /**
     * Bulk-mute the "interface down" false alarms that appear when onboarding a
     * switch — every admin-up port with no cable reads as down. Optionally scoped
     * to one device or site so an operator can clear a freshly-added switch in one
     * click. Also closes any open interface-down alert so the topology's degraded
     * (orange) markers clear with it.
     */
    public function suppressDown(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $query = DeviceInterface::where('status', 'down')
            ->where('admin_status', 'up')
            ->where('alarm_suppressed', false)
            ->when($data['device_id'] ?? null, fn ($q, $id) => $q->where('device_id', $id))
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->whereHas('device', fn ($d) => $d->where('site_id', $id)));

        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            return response()->json(['suppressed' => 0]);
        }

        DeviceInterface::whereIn('id', $ids)->update(['alarm_suppressed' => true]);
        // Resolve any open interface-down alert for the muted ports.
        DB::table('interface_alerts')
            ->whereIn('device_interface_id', $ids)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        return response()->json(['suppressed' => $ids->count()]);
    }

    /** Un-mute a single interface (re-arm its down alarm). */
    public function unsuppress(DeviceInterface $interface): JsonResponse
    {
        $interface->update(['alarm_suppressed' => false]);

        return response()->json($interface);
    }

    /** Mute a single interface — a dead/unused port that shouldn't alarm. Also
     *  closes any open alert on it so it drops off the active list. */
    public function suppress(DeviceInterface $interface): JsonResponse
    {
        $interface->update(['alarm_suppressed' => true]);
        $interface->alerts()->whereNull('ended_at')->update(['ended_at' => now(), 'cleared_manually' => true]);

        return response()->json($interface);
    }

    /** Busiest interfaces by utilisation %, for the capacity dashboard card. */
    public function top(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        $interfaces = DeviceInterface::query()
            ->with('device:id,name')
            ->where('status', 'up')
            ->where('speed_bps', '>', 0)
            // CASE instead of GREATEST so it runs on both MySQL and SQLite.
            ->orderByRaw('(CASE WHEN in_util_pct > out_util_pct THEN in_util_pct ELSE out_util_pct END) DESC')
            ->limit($limit)
            ->get();

        return response()->json($interfaces);
    }
}

