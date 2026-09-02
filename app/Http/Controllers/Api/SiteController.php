<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SiteRequest;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(private GeocodingService $geocoder)
    {
    }

    /** Resolve an address to coordinates on demand (the form's "Find on map" button). */
    public function geocode(Request $request): JsonResponse
    {
        $data = $request->validate(['address' => ['required', 'string', 'max:255']]);
        $coords = $this->geocoder->geocode($data['address']);

        return $coords
            ? response()->json($coords)
            : response()->json(['error' => 'No match for that address. Enter coordinates manually.'], 422);
    }

    public function index(): JsonResponse
    {
        $sites = Site::withCount(['devices', 'circuits'])
            ->with('hubs:id')
            ->orderBy('name')
            ->get();

        // Expose the hub ids a branch homes to (many-to-many), for the form + grouping.
        $sites->each(fn (Site $s) => $s->setAttribute('hub_site_ids', $s->hubs->pluck('id')->all())->unsetRelation('hubs'));

        return response()->json($sites);
    }

    /**
     * Everything a NOC wants to see about one location at a glance: the devices
     * that live there (with health + live alert counts), the ISP circuits that
     * feed it, and a rolled-up posture summary. Powers the expandable Sites rows.
     */
/**
     * The WAN-facing ports on this site's appliances, for the circuit editor.
     *
     * A circuit's wan_interface drives bandwidth attribution, so it must name a port
     * that is genuinely polled. Offering the real list beats a hardcoded wan0–wan3:
     * Massey already has a circuit landing on lan1, and a typo here silently costs
     * the circuit its throughput measurement.
     */
    public function wanInterfaces(Site $site): JsonResponse
    {
        $devices = Device::where('site_id', $site->id)
            ->whereIn('role', ['edgeconnect', 'firewall'])
            ->orderBy('name')
            ->get(['id', 'name']);

        $ports = DeviceInterface::whereIn('device_id', $devices->pluck('id'))
            ->orderBy('if_name')
            ->get(['device_id', 'if_name', 'if_canonical_name', 'status'])
            // Offer the port's REAL name where it has one. EdgeConnect keeps a human
            // label in ifDescr, so its wan0 shows up as whatever the circuit was
            // called ("BB,") — picking that stored a name no lookup can resolve.
            ->map(fn ($i) => [
                'if_name' => trim((string) $i->if_canonical_name) ?: $i->if_name,
                'label' => trim((string) $i->if_canonical_name) !== '' && trim((string) $i->if_name) !== trim((string) $i->if_canonical_name)
                    ? $i->if_name
                    : null,
                'device' => $devices->firstWhere('id', $i->device_id)?->name,
                'status' => $i->status,
            ])
            // Uplink-ish ports only — an operator picking a circuit's WAN port does not
            // want 40 access ports in the list.
            ->filter(fn ($p) => preg_match('/^(wan|lan|tlan|sp_wan|eth)/i', (string) $p['if_name']))
            ->sortBy('if_name')
            ->values();

        return response()->json(['data' => $ports]);
    }

    public function overview(Site $site): JsonResponse
    {
        $site->load([
            'devices' => fn ($q) => $q->orderBy('name'),
            'devices.health',
            'devices.interfaces:id,device_id,status,admin_status,alarm_suppressed',
            'devices.alarms' => fn ($q) => $q->whereNull('cleared_at'),
            'circuits.ispProvider:id,support_phone',
            'circuits.alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at'),
            'sharedCircuits.ispProvider:id,support_phone',
            'sharedCircuits.alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at'),
            'sharedCircuits.site:id,name',
        ]);

        $devices = $site->devices->map(function ($device) {
            // Down = physically down, excluding intentionally admin-shut ports.
            $ifDown = $device->interfaces->where('status', 'down')->where('admin_status', 'up')
                ->where('alarm_suppressed', false)->count();

            return [
                'id' => $device->id,
                'name' => $device->name,
                'ip_address' => $device->ip_address,
                'vendor' => $device->vendor,
                'model' => $device->model,
                'os_version' => $device->os_version,
                'role' => $device->role,
                'status' => $device->status,
                // Reachability — a device with an active device-unreachable alarm is DOWN,
                // regardless of its admin status. This is what the "online" dot must read.
                'is_down' => $device->isDown(),
                'serial_number' => $device->serial_number,
                'cpu_pct' => optional($device->health)->cpu_pct,
                'mem_pct' => optional($device->health)->mem_pct,
                'temperature_c' => optional($device->health)->temperature_c,
                'uptime_seconds' => optional($device->health)->uptime_seconds,
                'interfaces_total' => $device->interfaces->count(),
                'interfaces_down' => $ifDown,
                'active_alarms' => $device->alarms->count(),
            ];
        })->values();

        // If the site's SD-WAN edge is unreachable the site is dark — a circuit's gateway
        // ping can still answer and read "up", which is exactly the false-healthy we must
        // not show. Flag those circuits degraded (internet cannot be confirmed).
        $edgeUnreachable = $site->devices->contains(fn ($d) => $d->role === 'edgeconnect' && $d->isDown());

        $sharedIds = $site->sharedCircuits->pluck('id')->all();
        $circuits = $site->circuits->concat($site->sharedCircuits)->unique('id')->map(function ($circuit) use ($sharedIds, $edgeUnreachable) {
            $open = $circuit->alerts->first();
            $degraded = $edgeUnreachable && (bool) $circuit->monitoring_enabled;

            return [
                'id' => $circuit->id,
                'isp_name' => $circuit->isp_name,
                'circuit_id' => $circuit->circuit_id,
                'circuit_type' => $circuit->circuit_type,
                'monitored_ip' => $circuit->monitored_ip,
                'status' => $circuit->status,
                'transport_degraded' => $degraded,
                'transport_reason' => $degraded ? 'SD-WAN edge unreachable' : null,
                'ticket_number' => optional($open)->ticket_number,
                'support_phone' => optional($circuit->ispProvider)->support_phone,
                // Flag circuits shared in from another site (e.g. a CORP LAB uplink).
                'shared_from' => in_array($circuit->id, $sharedIds, true) ? optional($circuit->site)->name : null,
            ];
        })->values();

        return response()->json([
            'summary' => [
                'devices' => $devices->count(),
                'devices_down' => $devices->where('is_down', true)->count(),
                'circuits' => $circuits->count(),
                'circuits_down' => $circuits->where('status', 'down')->count(),
                'interfaces_down' => $devices->sum('interfaces_down'),
                'active_alarms' => $devices->sum('active_alarms'),
                'max_cpu' => $devices->max('cpu_pct'),
                'max_temp' => $devices->max('temperature_c'),
            ],
            'devices' => $devices,
            'circuits' => $circuits,
        ]);
    }

    public function store(SiteRequest $request): JsonResponse
    {
        $data = $this->withCoordinates($request->validated());
        $hubIds = $data['hub_site_ids'] ?? null;
        unset($data['hub_site_ids']);

        $site = Site::create($data);
        $this->syncHubs($site, $hubIds);

        return response()->json($this->withHubIds($site), 201);
    }

    public function show(Site $site): JsonResponse
    {
        $site->setAttribute('hub_site_ids', $site->hubs->pluck('id')->all());

        return response()->json($site);
    }

    public function update(SiteRequest $request, Site $site): JsonResponse
    {
        $data = $this->withCoordinates($request->validated());
        $hubIds = $data['hub_site_ids'] ?? null;
        unset($data['hub_site_ids']);

        $site->update($data);
        $this->syncHubs($site, $hubIds);

        return response()->json($this->withHubIds($site));
    }

    /** Attach the flat hub_site_ids array (and drop the relation) for the API shape. */
    private function withHubIds(Site $site): Site
    {
        $site->load('hubs:id');
        $site->setAttribute('hub_site_ids', $site->hubs->pluck('id')->all());

        return $site->unsetRelation('hubs');
    }

    /**
     * @param  array<int>|null  $hubIds
     */
    private function syncHubs(Site $site, ?array $hubIds): void
    {
        if ($hubIds === null) {
            return; // field not sent — leave existing assignments untouched
        }
        // A hub can't home to itself; only branches carry hubs.
        $site->hubs()->sync(array_values(array_filter($hubIds, fn ($id) => (int) $id !== $site->id)));
    }

    /**
     * Auto-fill coordinates from the address when none were supplied, so operators
     * don't have to look up lat/long by hand.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCoordinates(array $data): array
    {
        $hasCoords = ! empty($data['latitude']) && ! empty($data['longitude']);
        if (! $hasCoords && ! empty($data['address'])) {
            if ($coords = $this->geocoder->geocode($data['address'])) {
                $data = array_merge($data, $coords);
            }
        }

        return $data;
    }

    public function destroy(Site $site): JsonResponse
    {
        $site->delete();

        return response()->json(null, 204);
    }
}
