<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IpPrefix;
use App\Services\Ipam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * The real mask for a LAN, where the observed one is wrong.
 *
 * LAN ranges are inferred: every private address an appliance ARPs is bucketed into
 * the /24 it falls in. ARP reports who answered, never what the subnet is, so the mask
 * is the one thing on this page that cannot be discovered — 10.11.0.0 is a /23 and was
 * showing as two half-empty /24s, each claiming 254 usable addresses against a real
 * 510.
 *
 * A record here corrects that: the addresses inside it group as one range and capacity
 * is measured against its real size. It changes nothing about what was observed — the
 * same addresses, counted against the right denominator.
 */
class IpPrefixController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = IpPrefix::with('site:id,name,site_number')
            ->orderBy('cidr')
            ->get()
            ->map(fn (IpPrefix $p) => [
                ...$p->toArray(),
                // So the drawer can show what the correction is worth without the
                // caller doing prefix arithmetic.
                'usable' => Ipam::usableAddresses(Ipam::parseCidr($p->cidr)['prefix'] ?? 24),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->name;
        $row = IpPrefix::create($data);
        $this->forgetCaches();

        return response()->json($row->load('site:id,name'), 201);
    }

    public function update(Request $request, IpPrefix $ipPrefix): JsonResponse
    {
        $ipPrefix->update($this->validated($request, $ipPrefix->id));
        $this->forgetCaches();

        return response()->json($ipPrefix->fresh()->load('site:id,name'));
    }

    public function destroy(IpPrefix $ipPrefix): JsonResponse
    {
        $ipPrefix->delete();
        $this->forgetCaches();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'cidr' => ['required', 'string', 'max:49'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'label' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // Normalised before the uniqueness check, so 10.11.0.5/23 and 10.11.1.9/23
        // cannot both be stored as records of the same block.
        $canonical = Ipam::canonicalCidr($data['cidr']);
        if ($canonical === null) {
            abort(422, 'Enter a network in CIDR form, for example 10.11.0.0/23.');
        }

        // A /32 is one address; that is a reservation, not a subnet, and IpReservation
        // already records those with a purpose and an owner.
        $prefix = Ipam::parseCidr($canonical)['prefix'];
        if ($prefix > 30) {
            abort(422, 'A subnet needs at least two usable addresses — record a single address as a reservation instead.');
        }

        $request->merge(['cidr' => $canonical]);
        $request->validate([
            'cidr' => [Rule::unique('ip_prefixes', 'cidr')->ignore($ignoreId)],
        ], [
            'cidr.unique' => 'This network is already recorded.',
        ]);

        $data['cidr'] = $canonical;

        return $data;
    }

    /** The IPAM views are cached; a corrected mask has to show immediately. */
    private function forgetCaches(): void
    {
        Cache::forget('ipam:ranges');
        Cache::forget('ipam:space:'.Ipam::SUPERNET);
    }
}
