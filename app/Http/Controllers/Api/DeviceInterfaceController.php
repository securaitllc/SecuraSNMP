<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceInterface;
use App\Support\InterfaceHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeviceInterfaceController extends Controller
{
    /** Nominal poll interval (s) — used to turn a peak octet delta into a peak %. */
    private const POLL_SECONDS = 300;

    public function index(Request $request): JsonResponse
    {
        $interfaces = DeviceInterface::query()
            ->when($request->query('device_id'), fn ($query, $deviceId) => $query->where('device_id', $deviceId))
            // The open interface-down alert (if any) so the device page can show and
            // act on it — the interface index is the only per-device interface feed.
            ->with(['alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->orderBy('if_index')
            ->get();

        $this->attachHealth($interfaces);

        return response()->json($interfaces);
    }

    /**
     * Enrich each interface with a health verdict (Clean / CRC errors / Discards /
     * Flapping / Down) and a 24h peak utilisation, so the panel can show WHY a port
     * needs attention. The recent error/discard sums and peak deltas come from ONE
     * grouped query over the metric history (DB-side aggregation), not a per-row scan.
     */
    private function attachHealth(\Illuminate\Support\Collection $interfaces): void
    {
        if ($interfaces->isEmpty()) {
            return;
        }

        $ids = $interfaces->pluck('id');
        $since = now()->subDay();

        $agg = DB::table('interface_metric_history')
            ->selectRaw('device_interface_id,'
                .' SUM(in_errors_delta + out_errors_delta) as errors_recent,'
                .' SUM(in_discards_delta + out_discards_delta) as discards_recent,'
                .' MAX(in_octets_delta) as peak_in_delta,'
                .' MAX(out_octets_delta) as peak_out_delta')
            ->whereIn('device_interface_id', $ids)
            ->where('recorded_at', '>=', $since)
            ->groupBy('device_interface_id')
            ->get()
            ->keyBy('device_interface_id');

        $now = now();

        foreach ($interfaces as $if) {
            $row = $agg->get($if->id);
            $errors = (int) ($row->errors_recent ?? 0);
            $discards = (int) ($row->discards_recent ?? 0);

            $health = InterfaceHealth::classify($if, $errors, $discards, $now);

            $if->health = $health['status'];
            $if->health_attention = $health['attention'];
            $if->errors_recent = $errors;
            $if->discards_recent = $discards;
            $if->peak_util_pct = $this->peakUtil($if, $row);
        }
    }

    /** Highest utilisation % seen in any single poll over the last 24h. */
    private function peakUtil(DeviceInterface $if, ?object $row): float
    {
        $speed = (int) $if->speed_bps;
        if ($speed <= 0 || $row === null) {
            return 0.0;
        }

        $peakDelta = max((int) ($row->peak_in_delta ?? 0), (int) ($row->peak_out_delta ?? 0));

        return round(min(100, $peakDelta * 8 / ($speed * self::POLL_SECONDS) * 100), 1);
    }

    /**
     * Compact traffic sparkline series (bits/s) per interface for the whole device,
     * so the table can show a 24h-at-a-glance trend without a request per row. One
     * windowed query; bucketed in PHP. Bounded to keep even a 339-port switch cheap.
     */
    public function sparklines(Request $request): JsonResponse
    {
        $deviceId = $request->query('device_id');
        if (! $deviceId) {
            return response()->json([]);
        }

        $ids = DeviceInterface::where('device_id', $deviceId)->pluck('id');
        if ($ids->isEmpty()) {
            return response()->json([]);
        }

        // Last ~2h of history (24 cycles) — enough to read the shape of the trend,
        // small enough to return for every port in one call.
        $rows = DB::table('interface_metric_history')
            ->select('device_interface_id', 'in_octets_delta', 'out_octets_delta', 'recorded_at')
            ->whereIn('device_interface_id', $ids)
            ->where('recorded_at', '>=', now()->subHours(2))
            ->orderBy('device_interface_id')
            ->orderBy('recorded_at')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $bpsIn = (int) round($r->in_octets_delta * 8 / self::POLL_SECONDS);
            $bpsOut = (int) round($r->out_octets_delta * 8 / self::POLL_SECONDS);
            $out[$r->device_interface_id]['in'][] = $bpsIn;
            $out[$r->device_interface_id]['out'][] = $bpsOut;
        }

        return response()->json($out);
    }

    /** Attach or clear an operator note on an interface (a team-visible memo). */
    public function saveNote(Request $request, DeviceInterface $interface): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $note = isset($data['note']) ? trim($data['note']) : '';

        $interface->update($note === '' ? [
            'note' => null, 'note_by' => null, 'note_at' => null,
        ] : [
            'note' => $note,
            'note_by' => Auth::user()?->name,
            'note_at' => now(),
        ]);

        return response()->json($interface);
    }

    /**
     * Acknowledge a health condition (errors / discards / flapping). Stamps
     * health_ack_at so the pill goes quiet until a NEWER fault of that kind lands
     * — proactive "I've seen this" that re-arms itself, without a fleet alarm.
     */
    public function acknowledgeHealth(DeviceInterface $interface): JsonResponse
    {
        $interface->update([
            'health_ack_at' => now(),
            'health_ack_by' => Auth::user()?->name,
        ]);

        return response()->json($interface);
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

