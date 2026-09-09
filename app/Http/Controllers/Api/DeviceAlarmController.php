<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceAlarm;
use App\Services\AlarmGroupingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAlarmController extends Controller
{
    /**
     * Active alarms grouped by site → ISP circuit, for the per-provider view
     * (Alarms page, Site detail, Dashboard). `?site_id=` restricts to one site.
     */
    public function grouped(Request $request, AlarmGroupingService $grouper): JsonResponse
    {
        $siteId = $request->query('site_id');

        return response()->json(['sites' => $grouper->grouped($siteId ? (int) $siteId : null)]);
    }

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

    /**
     * Searchable Active + history alarm log for the dedicated Alarms page.
     *
     * Filters (scope / severity / site / free-text) run in the DB, and the result
     * is CAPPED (newest first) — a full-fleet history load must be windowed, per
     * the fleet-load rule, so a year of alarms can't OOM the request.
     */
    public function log(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'all'); // active | cleared | all
        $term = trim((string) $request->query('q', ''));
        $cap = 500;

        $alarms = DeviceAlarm::query()
            ->with(['device:id,name,site_id', 'device.site:id,name', 'acknowledgedBy:id,name', 'clearedBy:id,name'])
            ->when($scope === 'active', fn ($query) => $query->whereNull('cleared_at'))
            ->when($scope === 'cleared', fn ($query) => $query->whereNotNull('cleared_at'))
            ->when($request->query('severity'), fn ($query, $sev) => $query->where('severity', $sev))
            ->when($request->query('site_id'), fn ($query, $id) => $query->whereHas('device', fn ($d) => $d->where('site_id', $id)))
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
                $query->where(function ($sub) use ($like) {
                    $sub->where('alarm_id', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('ticket_number', 'like', $like)
                        ->orWhereHas('device', fn ($d) => $d->where('name', 'like', $like)
                            ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $like)));
                });
            })
            ->orderByDesc('first_seen_at')
            ->limit($cap)
            ->get();

        return response()->json([
            'alarms' => $alarms->map(fn (DeviceAlarm $a) => [
                'id' => $a->id,
                'alarm_id' => $a->alarm_id,
                'severity' => $a->severity ?? 'info',
                'description' => $a->description,
                'ticket_number' => $a->ticket_number,
                'device_name' => $a->device?->name,
                'site_name' => $a->device?->site?->name,
                'first_seen_at' => $a->first_seen_at,
                'cleared_at' => $a->cleared_at,
                'acknowledged_at' => $a->acknowledged_at,
                'acknowledged_by_name' => optional($a->acknowledgedBy)->name,
                'cleared_by_name' => optional($a->clearedBy)->name,
                'active' => $a->cleared_at === null,
            ])->all(),
            'counts' => [
                'all' => DeviceAlarm::count(),
                'active' => DeviceAlarm::whereNull('cleared_at')->count(),
                'cleared' => DeviceAlarm::whereNotNull('cleared_at')->count(),
            ],
            'capped' => $alarms->count() >= $cap,
        ]);
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
