<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubnetReservation;
use App\Services\Ipam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Address space claimed for a site that does not exist yet.
 *
 * Everything else on the IPAM page describes what the network reports. This is the one
 * record that describes intent: a block held on paper months before any kit is racked,
 * so the planner stops offering it and two service centres never open on one /24.
 *
 * A hold is RELEASED rather than deleted. Which blocks were claimed, by whom, and
 * handed back is the history that makes the next allocation defensible; a DELETE
 * throws it away.
 */
class SubnetReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = SubnetReservation::with('site:id,name,site_number')
            ->when(! $request->boolean('include_released'), fn ($q) => $q->held())
            ->orderBy('cidr')
            ->get()
            ->map(fn (SubnetReservation $r) => [
                ...$r->toArray(),
                'holder' => $r->holder,
                'overdue' => $r->overdue,
                'usable' => Ipam::usableAddresses(Ipam::parseCidr($r->cidr)['prefix'] ?? 24),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Ipam $ipam): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->name;

        $row = SubnetReservation::create($data);
        $this->forgetCaches();

        return response()->json([
            ...$row->load('site:id,name')->toArray(),
            // Held space that already has hosts in it is a collision, not a hold. It is
            // reported rather than refused: the operator may be recording a block that
            // is live today and moving off it, and only they can tell the difference.
            'occupied' => $this->occupancy($ipam, $row->cidr),
        ], 201);
    }

    public function update(Request $request, SubnetReservation $subnetReservation): JsonResponse
    {
        $subnetReservation->update($this->validated($request, $subnetReservation->id));
        $this->forgetCaches();

        return response()->json($subnetReservation->fresh()->load('site:id,name'));
    }

    /**
     * Hand the block back.
     *
     * The row stays. A released hold is how the next planner learns that this block
     * was considered and freed on purpose, rather than never claimed at all.
     */
    public function release(Request $request, SubnetReservation $subnetReservation): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $subnetReservation->update([
            'released_at' => now(),
            'released_by' => $request->user()?->name,
            'note' => $data['note'] ?? $subnetReservation->note,
        ]);
        $this->forgetCaches();

        return response()->json($subnetReservation->fresh());
    }

    public function destroy(SubnetReservation $subnetReservation): JsonResponse
    {
        $subnetReservation->delete();
        $this->forgetCaches();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'cidr' => ['required', 'string', 'max:49'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'site_label' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'planned_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $canonical = Ipam::canonicalCidr($data['cidr']);
        if ($canonical === null) {
            abort(422, 'Enter a network in CIDR form, for example 10.200.180.0/24.');
        }

        // A single address is IpReservation's job — it records a NAT pool or a VIP with
        // a purpose and an owner. This table exists for blocks.
        $prefix = Ipam::parseCidr($canonical)['prefix'];
        if ($prefix > 30) {
            abort(422, 'A subnet needs at least two usable addresses — record a single address as a reservation instead.');
        }

        // The whole point of the table is that a block is claimed once. Two holds over
        // the same space is the collision this prevents, so it is refused outright
        // rather than resolved by whoever wrote last.
        $clash = SubnetReservation::held()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->first(fn (SubnetReservation $r) => self::overlaps($canonical, $r->cidr));

        if ($clash) {
            abort(422, "That space is already held by {$clash->cidr}".
                ($clash->holder ? " for {$clash->holder}" : '').
                '. Release that reservation first, or choose another block.');
        }

        $data['cidr'] = $canonical;
        // A hold needs to say who it is for. Anonymous blocks are how reserved space
        // outlives the reason it was reserved.
        if (blank($data['site_id'] ?? null) && blank($data['site_label'] ?? null)) {
            abort(422, 'Name the site this block is for, so a future planner knows what it is holding.');
        }

        return $data;
    }

    /** Do two networks share any address? */
    public static function overlaps(string $a, string $b): bool
    {
        $x = Ipam::parseCidr($a);
        $y = Ipam::parseCidr($b);
        if ($x === null || $y === null) {
            return false;
        }

        // Compare on the SHORTER prefix: the wider network is the one that would
        // contain the other, whichever order they arrive in.
        $shortest = min($x['prefix'], $y['prefix']);
        $mask = $shortest === 0 ? 0 : (~0 << (32 - $shortest)) & 0xFFFFFFFF;

        return (ip2long($x['base']) & $mask) === (ip2long($y['base']) & $mask);
    }

    /** How many addresses are already live inside a block being claimed. */
    private function occupancy(Ipam $ipam, string $cidr): int
    {
        $net = Ipam::parseCidr($cidr);
        if ($net === null || $net['prefix'] < 24) {
            return 0;   // detail() enumerates one range; a supernet is not one.
        }

        // `confirmed`, never usable-minus-free: an unidentifiable MAC answering for
        // half a range must not be read as half a range of hosts. See Ipam::detail().
        return (int) ($ipam->detail(Ipam::slashTwentyFour($net['base']) ?? $cidr)['summary']['confirmed'] ?? 0);
    }

    /** The IPAM views are cached; a new hold has to show on the planner immediately. */
    private function forgetCaches(): void
    {
        Cache::forget('ipam:ranges');
        Cache::forget('ipam:space:'.Ipam::SUPERNET);
    }
}
