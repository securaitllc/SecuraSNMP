<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MacAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MacAddressController extends Controller
{
    /** Max rows returned; the UI narrows with search when this is hit. */
    private const CAP = 1000;

    /**
     * Learned-MAC lookup. `q` matches a MAC (partial, any separator) or a vendor
     * name; scope with device_id / interface_id. Newest-seen first, capped.
     */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $qMac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $q)); // separator-less MAC search
        $deviceId = $request->query('device_id');
        $interfaceId = $request->query('interface_id');

        // Column names are qualified so the same filters work with or without the
        // devices join used by the fleet-wide de-dupe below.
        $applyFilters = function ($x) use ($deviceId, $interfaceId, $q, $qMac) {
            $x->when($deviceId, fn ($x, $id) => $x->where('mac_addresses.device_id', $id))
                ->when($interfaceId, fn ($x, $id) => $x->where('mac_addresses.device_interface_id', $id))
                ->when($q !== '', function ($x) use ($q, $qMac) {
                    $x->where(function ($w) use ($q, $qMac) {
                        $w->where('mac_addresses.oui_vendor', 'like', "%{$q}%")
                            ->orWhere('mac_addresses.mac', 'like', "%{$q}%");
                        if ($qMac !== '') {
                            $w->orWhereRaw("REPLACE(mac_addresses.mac, ':', '') LIKE ?", ["%{$qMac}%"]);
                        }
                    });
                });
        };

        $eager = ['device:id,name,site_id', 'device.site:id,name', 'deviceInterface:id,if_name,status'];

        if ($deviceId || $interfaceId) {
            // Scoped to one device/interface: show every MAC there (the poller keeps one
            // row per device+mac), newest first. unique('mac') guards any fork not yet
            // collapsed by the next poll.
            $rows = MacAddress::query()->with($eager)->tap($applyFilters)
                ->orderByDesc('last_seen_at')->limit(self::CAP + 1)->get()
                ->unique('mac')->values();
        } else {
            // Fleet view: ONE row per (site, MAC). A distribution switch learns the whole
            // fleet's MACs, and a Wi-Fi client roams a site's switches/buildings — so the
            // same endpoint appeared many times and a couple of core switches filled the
            // cap before distant sites showed at all. Keep the freshest sighting per site
            // via a window rank, THEN cap, so the 1000 covers 1000 distinct site-endpoints.
            $ranked = MacAddress::query()
                ->join('devices', 'devices.id', '=', 'mac_addresses.device_id')
                ->tap($applyFilters)
                ->select('mac_addresses.id')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY devices.site_id, mac_addresses.mac ORDER BY mac_addresses.last_seen_at DESC) as rn');

            $ids = DB::query()->fromSub($ranked, 't')->where('rn', 1)->limit(self::CAP + 1)->pluck('id');

            $rows = MacAddress::query()->with($eager)->whereIn('id', $ids)
                ->orderByDesc('last_seen_at')->get();
        }

        $capped = $rows->count() > self::CAP;
        $rows = $rows->take(self::CAP);

        return response()->json([
            'capped' => $capped,
            'data' => $rows->map(fn (MacAddress $m) => [
                'id' => $m->id,
                'mac' => $m->mac,
                'vlan' => $m->vlan,
                'oui_vendor' => $m->oui_vendor,
                'device_id' => $m->device_id,
                'device_name' => optional($m->device)->name,
                'site_id' => optional($m->device)->site_id,
                'site_name' => optional(optional($m->device)->site)->name,
                'interface_id' => $m->device_interface_id,
                'interface_name' => optional($m->deviceInterface)->if_name,
                'interface_status' => optional($m->deviceInterface)->status,
                'first_seen_at' => $m->first_seen_at,
                'last_seen_at' => $m->last_seen_at,
            ])->values(),
        ]);
    }
}
