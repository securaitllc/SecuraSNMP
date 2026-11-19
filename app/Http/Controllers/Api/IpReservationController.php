<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IpReservation;
use App\Services\Ipam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Addresses recorded by hand — the allocations no protocol reports.
 *
 * A firewall's NAT pools and VIPs occupy real public space and appear in no SNMP
 * table, so they can only be written down. An address recorded here is never offered
 * as free.
 */
class IpReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = IpReservation::with(['site:id,name,site_number', 'device:id,name'])
            ->when($request->string('cidr')->toString(), function ($q, $cidr) {
                // Only the reservations inside one range.
                $net = Ipam::parseCidr($cidr);
                if ($net === null) {
                    return $q;
                }
                $prefix = implode('.', array_slice(explode('.', $net['base']), 0, 3)).'.';

                return $q->where('ip', 'like', $prefix.'%');
            })
            ->orderBy('ip')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->name;
        $row = IpReservation::create($data);
        $this->forgetCaches();

        return response()->json($row->load(['site:id,name', 'device:id,name']), 201);
    }

    public function update(Request $request, IpReservation $ipReservation): JsonResponse
    {
        $data = $this->validated($request, $ipReservation->id);
        $ipReservation->update($data);
        $this->forgetCaches();

        return response()->json($ipReservation->fresh()->load(['site:id,name', 'device:id,name']));
    }

    public function destroy(IpReservation $ipReservation): JsonResponse
    {
        $ipReservation->delete();
        $this->forgetCaches();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'ip' => ['required', 'ipv4', Rule::unique('ip_reservations', 'ip')->ignore($ignoreId)],
            'prefix_len' => ['nullable', 'integer', 'between:0,32'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'label' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', Rule::in(IpReservation::PURPOSES)],
            'assignment' => ['required', Rule::in(IpReservation::ASSIGNMENTS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            // Two people recording one address is the collision this exists to prevent;
            // say so in the operator's terms rather than "the ip has already been taken".
            'ip.unique' => 'This address is already recorded.',
        ]);
    }

    /** The IPAM views are cached; a new reservation has to show immediately. */
    private function forgetCaches(): void
    {
        Cache::forget('ipam:ranges');
        Cache::forget('ipam:space:'.Ipam::SUPERNET);
    }
}
