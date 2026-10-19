<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ipam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IpamController extends Controller
{
    /**
     * Every range grouped by site.
     *
     * Building this walks ~13k ARP rows, so the whole fleet view is cached briefly —
     * the ARP poller only refreshes every 180s, so a 60s cache can never make the page
     * meaningfully staler than its source. A single-site view is cheap and uncached.
     */
    public function ranges(Request $request, Ipam $ipam): JsonResponse
    {
        $siteId = $request->integer('site_id') ?: null;

        if ($siteId) {
            return response()->json($ipam->ranges($siteId));
        }

        return response()->json(Cache::remember('ipam:ranges', 60, fn () => $ipam->ranges()));
    }

    /** Every address inside one range. */
    public function detail(Request $request, Ipam $ipam): JsonResponse
    {
        $data = $request->validate([
            'cidr' => ['required', 'string', 'max:43'],
            'site_id' => ['nullable', 'integer'],
        ]);

        if (Ipam::parseCidr($data['cidr']) === null) {
            return response()->json(['message' => 'Not a valid CIDR range.'], 422);
        }

        return response()->json($ipam->detail($data['cidr'], $data['site_id'] ?? null));
    }

    /** Which /24s of the corporate supernet are free for a new site. */
    public function space(Request $request, Ipam $ipam): JsonResponse
    {
        $supernet = (string) $request->string('supernet', Ipam::SUPERNET);
        $net = Ipam::parseCidr($supernet);

        if ($net === null || $net['prefix'] !== 16) {
            return response()->json(['message' => 'The planner works on a /16 supernet.'], 422);
        }

        return response()->json(Cache::remember("ipam:space:{$supernet}", 60, fn () => $ipam->space($supernet)));
    }
}
