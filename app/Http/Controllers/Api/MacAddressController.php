<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArpEntry;
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
        $siteId = $request->query('site_id');
        // An IP-shaped term also searches the ARP table (endpoints share 192.168.255.x across
        // every site, so this is how an operator traces "which site owns this IP").
        $ipLike = preg_match('/^[0-9]{1,3}(\.[0-9]{0,3}){0,3}$/', $q) ? $q : null;

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
            //
            // The busiest site (HQ) alone has >1000 distinct endpoints, so an unfiltered
            // view is still dominated by it — the term therefore also matches SITE and
            // DEVICE name, letting an operator scope to "#185" / "Cleveland" / a switch and
            // see that location's endpoints.
            $ranked = MacAddress::query()
                ->join('devices', 'devices.id', '=', 'mac_addresses.device_id')
                ->leftJoin('sites', 'sites.id', '=', 'devices.site_id')
                ->when($siteId, fn ($x, $id) => $x->where('devices.site_id', $id))
                ->when($q !== '', function ($x) use ($q, $qMac, $ipLike) {
                    $x->where(function ($w) use ($q, $qMac, $ipLike) {
                        $w->where('mac_addresses.oui_vendor', 'like', "%{$q}%")
                            ->orWhere('mac_addresses.mac', 'like', "%{$q}%")
                            ->orWhere('devices.name', 'like', "%{$q}%")
                            ->orWhere('sites.name', 'like', "%{$q}%");
                        if ($qMac !== '') {
                            $w->orWhereRaw("REPLACE(mac_addresses.mac, ':', '') LIKE ?", ["%{$qMac}%"]);
                        }
                        if ($ipLike !== null) {
                            $w->orWhereExists(fn ($e) => $e->from('arp_entries')
                                ->whereColumn('arp_entries.mac', 'mac_addresses.mac')
                                ->whereColumn('arp_entries.site_id', 'devices.site_id')
                                ->where('arp_entries.ip', 'like', "{$ipLike}%"));
                        }
                    });
                })
                ->select('mac_addresses.id')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY devices.site_id, mac_addresses.mac ORDER BY mac_addresses.last_seen_at DESC) as rn');

            $ids = DB::query()->fromSub($ranked, 't')->where('rn', 1)->limit(self::CAP + 1)->pluck('id');

            $rows = MacAddress::query()->with($eager)->whereIn('id', $ids)
                ->orderByDesc('last_seen_at')->get();
        }

        $capped = $rows->count() > self::CAP;
        $rows = $rows->take(self::CAP);

        // IP for each (mac, site) from the ARP store — the endpoint's address, keyed by site
        // because the same private range repeats fleet-wide. Freshest sighting wins.
        $ipMap = [];
        $macs = $rows->pluck('mac')->unique()->all();
        $sites = $rows->map(fn ($m) => optional($m->device)->site_id)->filter()->unique()->all();
        if ($macs !== [] && $sites !== []) {
            ArpEntry::query()->whereIn('mac', $macs)->whereIn('site_id', $sites)
                ->orderByDesc('last_seen_at')->get(['mac', 'site_id', 'ip'])
                ->each(function ($a) use (&$ipMap) {
                    $ipMap["{$a->mac}|{$a->site_id}"] ??= $a->ip;
                });
        }

        return response()->json([
            'capped' => $capped,
            'data' => $rows->map(fn (MacAddress $m) => [
                'id' => $m->id,
                'mac' => $m->mac,
                'ip' => $ipMap["{$m->mac}|".optional($m->device)->site_id] ?? null,
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
