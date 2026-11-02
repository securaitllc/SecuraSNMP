<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CircuitAlert;
use App\Models\DeviceAlarm;
use App\Models\InterfaceAlert;
use App\Models\NextHopAlert;
use App\Models\TunnelAlert;
use App\Support\Maintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Unified alarm console — ONE place to see and clear EVERY alert type.
 *
 * Nodus raises five independent kinds of alert, each in its own table with its own
 * poller lifecycle: device (SNMP, DeviceAlarm, closes via cleared_at), tunnel
 * (SSH, TunnelAlert, ended_at), interface/port (SNMP, InterfaceAlert, ended_at),
 * circuit (ICMP, CircuitAlert, ended_at) and gateway/next-hop (SSH, NextHopAlert,
 * ended_at). Clearing them used to be scattered — the dashboard could clear device
 * + tunnel, the device page interface, the circuits page circuit, and next-hop had
 * no manual clear at all. A tunnel-down to a decommissioned hub, for example, had
 * no reliable button anywhere outside the dashboard widget.
 *
 * This console normalises all five into one list and clears/acks any mix of them in
 * one call, so an operator can clear ANY alarm from one screen. Clears set the same
 * cleared_manually flag the per-type endpoints do, so the no-resurrect rule still
 * holds: an alert only reopens on a genuine recovery-then-fail (a flap). A next-hop
 * alert for a gateway that is still truly down will honestly reopen next sweep — by
 * design, a real outage is never hidden.
 */
class AlertConsoleController extends Controller
{
    /**
     * Per-kind config: the model, the column that records the close time, and the
     * open scope. Device alarms close by cleared_at; every other kind by ended_at.
     *
     * @var array<string, array{model: class-string, close: string}>
     */
    private const KINDS = [
        'device' => ['model' => DeviceAlarm::class, 'close' => 'cleared_at'],
        'tunnel' => ['model' => TunnelAlert::class, 'close' => 'ended_at'],
        'interface' => ['model' => InterfaceAlert::class, 'close' => 'ended_at'],
        'circuit' => ['model' => CircuitAlert::class, 'close' => 'ended_at'],
        'nexthop' => ['model' => NextHopAlert::class, 'close' => 'ended_at'],
    ];

    /**
     * Every open alert across all five kinds, normalised to one row shape and
     * capped newest-first. Filters (kind / severity / site / free text) are applied
     * after normalisation so they work uniformly across the different tables.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'active'); // active | all
        $kindFilter = $request->query('kind');
        $severity = $request->query('severity');
        $siteId = $request->query('site_id') !== null ? (int) $request->query('site_id') : null;
        $term = strtolower(trim((string) $request->query('q', '')));
        $cap = 500;

        $maintenance = Maintenance::deviceIds();

        $rows = collect();
        foreach (array_keys(self::KINDS) as $kind) {
            if ($kindFilter && $kindFilter !== $kind) {
                continue;
            }
            $rows = $rows->merge($this->rowsFor($kind, $scope === 'all', $maintenance));
        }

        $filtered = $rows
            ->when($severity, fn (Collection $c) => $c->where('severity', $severity))
            ->when($siteId, fn (Collection $c) => $c->where('site_id', $siteId))
            ->when($term !== '', fn (Collection $c) => $c->filter(fn ($r) => str_contains(
                strtolower(($r['label'] ?? '').' '.($r['device_name'] ?? '').' '.($r['site_name'] ?? '').' '.($r['ticket_number'] ?? '')),
                $term
            )))
            ->sortByDesc('started_at')
            ->values();

        return response()->json([
            'alerts' => $filtered->take($cap)->all(),
            'counts' => [
                'all' => $rows->count(),
                'by_kind' => $rows->groupBy('kind')->map->count(),
            ],
            'capped' => $filtered->count() > $cap,
        ]);
    }

    /** Acknowledge any mix of alerts in one call. */
    public function acknowledge(Request $request): JsonResponse
    {
        $items = $this->validateItems($request);
        $note = $request->input('note');
        $userId = $request->user()->id;

        $acked = 0;
        foreach ($this->groupByKind($items) as $kind => $ids) {
            $acked += self::KINDS[$kind]['model']::whereIn('id', $ids)
                ->whereNull(self::KINDS[$kind]['close'])
                ->whereNull('acknowledged_at')
                ->update([
                    'acknowledged_at' => now(),
                    'acknowledged_by' => $userId,
                    'ack_note' => $note ?: null,
                ]);
        }

        return response()->json(['acknowledged' => $acked, 'requested' => count($items)]);
    }

    /**
     * Clear any mix of alerts in one call. Ids are explicit (the exact rows shown to
     * the operator), never a filter, so a race with a poller cannot widen the blast
     * radius between rendering and confirming.
     */
    public function clear(Request $request): JsonResponse
    {
        $items = $this->validateItems($request);
        $note = $request->input('note');
        $userId = $request->user()->id;

        $cleared = 0;
        foreach ($this->groupByKind($items) as $kind => $ids) {
            $close = self::KINDS[$kind]['close'];
            $cleared += self::KINDS[$kind]['model']::whereIn('id', $ids)
                ->whereNull($close)
                ->update([
                    $close => now(),
                    'cleared_by' => $userId,
                    'clear_note' => $note ?: null,
                    'cleared_manually' => true,
                ]);
        }

        return response()->json(['cleared' => $cleared, 'requested' => count($items)]);
    }

    /**
     * Validate the {items:[{kind,id}]} payload and drop any unknown kind.
     *
     * @return list<array{kind: string, id: int}>
     */
    private function validateItems(Request $request): array
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.kind' => ['required', 'string'],
            'items.*.id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return collect($data['items'])
            ->filter(fn ($i) => isset(self::KINDS[$i['kind']]))
            ->map(fn ($i) => ['kind' => $i['kind'], 'id' => (int) $i['id']])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{kind: string, id: int}>  $items
     * @return array<string, list<int>>
     */
    private function groupByKind(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $out[$i['kind']][] = $i['id'];
        }

        return $out;
    }

    /**
     * Normalise one kind's open (or all) alerts to the shared row shape.
     *
     * @param  array<int, bool>  $maintenance  device-id => in-maintenance
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function rowsFor(string $kind, bool $all, array $maintenance): Collection
    {
        $close = self::KINDS[$kind]['close'];

        return match ($kind) {
            'device' => DeviceAlarm::query()
                ->when(! $all, fn ($q) => $q->whereNull($close))
                ->with(['device:id,name,site_id', 'device.site:id,name', 'acknowledgedBy:id,name'])
                ->orderByDesc('first_seen_at')->limit(500)->get()
                ->map(fn (DeviceAlarm $a) => $this->row('device', $a->id, $a->severity ?? 'info', $a->description, $a->device_id, $a->device?->name, $a->device?->site_id, $a->device?->site?->name, $a->first_seen_at, $a->ticket_number, $a->acknowledged_at, $a->acknowledgedBy?->name, $a->cleared_at === null, isset($maintenance[$a->device_id]))),

            'tunnel' => TunnelAlert::query()
                ->when(! $all, fn ($q) => $q->whereNull($close))
                ->with(['tunnel:id,device_id,tunnel_name', 'tunnel.device:id,name,site_id', 'tunnel.device.site:id,name', 'acknowledgedBy:id,name'])
                ->orderByDesc('started_at')->limit(500)->get()
                ->map(fn (TunnelAlert $a) => $this->row('tunnel', $a->id, 'critical', 'Tunnel down — '.($a->tunnel?->tunnel_name ?? 'tunnel'), $a->tunnel?->device_id, $a->tunnel?->device?->name, $a->tunnel?->device?->site_id, $a->tunnel?->device?->site?->name, $a->started_at, $a->ticket_number, $a->acknowledged_at, $a->acknowledgedBy?->name, $a->ended_at === null, isset($maintenance[$a->tunnel?->device_id]))),

            'interface' => InterfaceAlert::query()
                ->when(! $all, fn ($q) => $q->whereNull($close))
                ->with(['deviceInterface:id,device_id,if_name', 'deviceInterface.device:id,name,site_id', 'deviceInterface.device.site:id,name', 'acknowledgedBy:id,name'])
                ->orderByDesc('started_at')->limit(500)->get()
                ->map(fn (InterfaceAlert $a) => $this->row('interface', $a->id, $a->severity ?? 'warning', 'Port down — '.($a->deviceInterface?->if_name ?? 'interface'), $a->deviceInterface?->device_id, $a->deviceInterface?->device?->name, $a->deviceInterface?->device?->site_id, $a->deviceInterface?->device?->site?->name, $a->started_at, $a->ticket_number, $a->acknowledged_at, $a->acknowledgedBy?->name, $a->ended_at === null, isset($maintenance[$a->deviceInterface?->device_id]))),

            'circuit' => CircuitAlert::query()
                ->when(! $all, fn ($q) => $q->whereNull($close))
                ->with(['circuit:id,site_id,isp_name,circuit_type', 'circuit.site:id,name', 'acknowledgedBy:id,name'])
                ->orderByDesc('started_at')->limit(500)->get()
                ->map(fn (CircuitAlert $a) => $this->row('circuit', $a->id, 'critical', 'Circuit down — '.(trim(($a->circuit?->isp_name ?? '').' '.($a->circuit?->circuit_type ?? '')) ?: 'circuit'), null, null, $a->circuit?->site_id, $a->circuit?->site?->name, $a->started_at, $a->ticket_number, $a->acknowledged_at, $a->acknowledgedBy?->name, $a->ended_at === null, false)),

            'nexthop' => NextHopAlert::query()
                ->when(! $all, fn ($q) => $q->whereNull($close))
                ->with(['device:id,name,site_id', 'device.site:id,name', 'nextHop:id,ip_address', 'acknowledgedBy:id,name'])
                ->orderByDesc('started_at')->limit(500)->get()
                ->map(fn (NextHopAlert $a) => $this->row('nexthop', $a->id, 'critical', 'Gateway unreachable'.($a->nextHop?->ip_address ? ' — '.$a->nextHop->ip_address : ''), $a->device_id, $a->device?->name, $a->device?->site_id, $a->device?->site?->name, $a->started_at, null, $a->acknowledged_at, $a->acknowledgedBy?->name, $a->ended_at === null, isset($maintenance[$a->device_id]))),

            default => collect(),
        };
    }

    /** @return array<string, mixed> */
    private function row(string $kind, int $id, string $severity, ?string $label, ?int $deviceId, ?string $deviceName, ?int $siteId, ?string $siteName, $startedAt, ?string $ticket, $ackedAt, ?string $ackedByName, bool $active, bool $inMaintenance): array
    {
        return [
            'kind' => $kind,
            'id' => $id,
            'severity' => $severity,
            'label' => $label,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'site_id' => $siteId,
            'site_name' => $siteName,
            'started_at' => $startedAt,
            'ticket_number' => $ticket,
            'acknowledged_at' => $ackedAt,
            'acknowledged_by_name' => $ackedByName,
            'active' => $active,
            'in_maintenance' => $inMaintenance,
        ];
    }
}
