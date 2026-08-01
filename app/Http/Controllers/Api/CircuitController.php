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

        $circuits->each(fn (Circuit $c) => $c->setAttribute('shared_site_ids', $c->sharedSites->pluck('id')->all()));

        return response()->json($circuits);
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
