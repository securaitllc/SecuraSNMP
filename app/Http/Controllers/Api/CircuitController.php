<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CircuitRequest;
use App\Models\Circuit;
use App\Services\CircuitDeduplicator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CircuitController extends Controller
{
    /**
     * Collapse true duplicates (same site + same monitored IP) to one row.
     * dry_run (default true) previews the keep/delete plan without writing.
     */
    public function dedupe(Request $request, CircuitDeduplicator $dedup): JsonResponse
    {
        if ($request->boolean('dry_run', true)) {
            $plan = $dedup->plan();

            return response()->json([
                'dry_run' => true,
                'groups' => count($plan),
                'would_delete' => array_sum(array_map(fn ($g) => count($g['delete']), $plan)),
                'plan' => $plan,
            ]);
        }

        return response()->json(['dry_run' => false] + $dedup->apply());
    }

    public function index(Request $request): JsonResponse
    {
        $circuits = Circuit::query()->with(['ispProvider', 'sharedSites:id,name'])
            ->when($request->query('site_id'), fn ($query, $siteId) => $query->where('site_id', $siteId))
            ->orderBy('isp_name')
            ->get();

        $circuits->each(function (Circuit $c) {
            $c->setAttribute('shared_site_ids', $c->sharedSites->pluck('id')->all());
            $c->setAttribute('contract_status', $c->contractStatus());
            $c->setAttribute('days_to_expiry', $c->daysToExpiry());
        });

        return response()->json($circuits);
    }

    /**
     * Record a contract renewal (admin). Sets the new end date — from an explicit
     * date, or computed from a term in months off the current end (or today) — and
     * writes a renewal row for the accountability trail. Never a silent overwrite.
     */
    public function renew(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate([
            'new_end_date' => ['nullable', 'date', 'required_without:term_months'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:120', 'required_without:new_end_date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $previous = $circuit->contract_end_date;
        $newEnd = isset($data['new_end_date'])
            ? \Illuminate\Support\Carbon::parse($data['new_end_date'])
            : ($previous && $previous->isFuture() ? $previous->copy() : now())->addMonths((int) $data['term_months']);

        $circuit->renewals()->create([
            'previous_end_date' => $previous,
            'new_end_date' => $newEnd,
            'term_months' => $data['term_months'] ?? null,
            'note' => $data['note'] ?? null,
            'renewed_by' => $request->user()->id,
        ]);

        $circuit->update([
            'contract_end_date' => $newEnd,
            'contract_term_months' => $data['term_months'] ?? $circuit->contract_term_months,
        ]);

        return response()->json($circuit->fresh()->load(['renewals.renewedBy:id,name']));
    }

    /** The renewal history for a circuit (accountability trail). */
    public function renewals(Circuit $circuit): JsonResponse
    {
        $rows = $circuit->renewals()->with('renewedBy:id,name')->get()
            ->map(fn (\App\Models\CircuitRenewal $r) => [
                ...$r->toArray(),
                'renewed_by_name' => optional($r->renewedBy)->name,
            ]);

        return response()->json($rows);
    }

    public function store(CircuitRequest $request): JsonResponse
    {
        [$data, $shared] = $this->extractShared($request->validated());
        $circuit = Circuit::create($data);
        $this->syncShared($circuit, $shared);

        return response()->json($circuit->load('sharedSites:id,name'), 201);
    }

    public function show(Circuit $circuit): JsonResponse
    {
        return response()->json($circuit->load(['ispProvider', 'sharedSites:id,name']));
    }

    public function update(CircuitRequest $request, Circuit $circuit): JsonResponse
    {
        [$data, $shared] = $this->extractShared($request->validated());
        $circuit->update($data);
        $this->syncShared($circuit, $shared);

        return response()->json($circuit->load('sharedSites:id,name'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<int>|null}
     */
    private function extractShared(array $data): array
    {
        $shared = $data['shared_site_ids'] ?? null;
        unset($data['shared_site_ids']);

        return [$data, $shared];
    }

    private function syncShared(Circuit $circuit, ?array $sharedIds): void
    {
        if ($sharedIds === null) {
            return;
        }
        // A circuit never "shares" with its own owner site.
        $circuit->sharedSites()->sync(array_values(array_filter($sharedIds, fn ($id) => (int) $id !== $circuit->site_id)));
    }

    /**
     * Take a circuit in/out of monitoring for a planned disconnect. Disabling
     * stops the ping and resolves any open outage so it doesn't sit as "down".
     */
    public function setMonitoring(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $circuit->update([
            'monitoring_enabled' => $data['enabled'],
            // Coming out of maintenance, let the next poll set the real state.
            'status' => $data['enabled'] ? $circuit->status : 'up',
        ]);

        if (! $data['enabled']) {
            $circuit->alerts()->whereNull('ended_at')->update(['ended_at' => now()]);
        }

        return response()->json($circuit->fresh());
    }

    public function destroy(Circuit $circuit): JsonResponse
    {
        $circuit->delete();

        return response()->json(null, 204);
    }
}
