<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MacAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MacAddressController extends Controller
{
    /**
     * Learned-MAC lookup. `q` matches a MAC (partial, any separator) or a vendor
     * name; scope with device_id / interface_id. Newest-seen first, capped.
     */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $qMac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $q)); // for a separator-less MAC search

        $rows = MacAddress::query()
            ->with(['device:id,name', 'deviceInterface:id,if_name,status'])
            ->when($request->query('device_id'), fn ($x, $id) => $x->where('device_id', $id))
            ->when($request->query('interface_id'), fn ($x, $id) => $x->where('device_interface_id', $id))
            ->when($q !== '', function ($x) use ($q, $qMac) {
                $x->where(function ($w) use ($q, $qMac) {
                    $w->where('oui_vendor', 'like', "%{$q}%")
                        ->orWhere('mac', 'like', "%{$q}%");
                    if ($qMac !== '') {
                        // match a MAC typed without separators against the stored AA:BB:… form
                        $w->orWhereRaw("REPLACE(mac, ':', '') LIKE ?", ["%{$qMac}%"]);
                    }
                });
            })
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get();

        return response()->json($rows->map(fn (MacAddress $m) => [
            'id' => $m->id,
            'mac' => $m->mac,
            'vlan' => $m->vlan,
            'oui_vendor' => $m->oui_vendor,
            'device_id' => $m->device_id,
            'device_name' => optional($m->device)->name,
            'interface_id' => $m->device_interface_id,
            'interface_name' => optional($m->deviceInterface)->if_name,
            'interface_status' => optional($m->deviceInterface)->status,
            'first_seen_at' => $m->first_seen_at,
            'last_seen_at' => $m->last_seen_at,
        ]));
    }
}
