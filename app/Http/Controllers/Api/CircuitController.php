<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CircuitRequest;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Services\AlarmCircuitResolver;
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

        $transportDegraded = $this->transportDegraded($circuits);

        $circuits->each(function (Circuit $c) use ($transportDegraded) {
            $c->setAttribute('shared_site_ids', $c->sharedSites->pluck('id')->all());
            $c->setAttribute('contract_status', $c->contractStatus());
            $c->setAttribute('days_to_expiry', $c->daysToExpiry());
            // The appliance's authoritative view: an SD-WAN transport can be failing
            // (IP-SLA / gateway alarm) while the gateway ICMP ping still answers 0% loss.
            $c->setAttribute('transport_degraded', isset($transportDegraded[$c->id]));
            $c->setAttribute('transport_reason', $transportDegraded[$c->id] ?? null);
        });

        return response()->json($circuits);
    }

    /**
     * Circuits whose SD-WAN transport is degraded per the appliance's OWN signal — an
     * ACTIVE gateway or IP-SLA (wanN) alarm on the site's EdgeConnect, mapped to the
     * circuit by the same resolver the alarm grouping uses. Because it is driven purely
     * by active (un-cleared) alarms, it clears the instant the alarm does — a paused
     * circuit is already alarm-muted, so it can never linger as a false positive.
     *
     * @param  \Illuminate\Support\Collection<int,Circuit>  $circuits
     * @return array<int,string>  circuit id => reason
     */
    private function transportDegraded($circuits): array
    {
        $siteIds = $circuits->pluck('site_id')->filter()->unique();
        if ($siteIds->isEmpty()) {
            return [];
        }

        $deviceSite = Device::whereIn('site_id', $siteIds)->where('role', 'edgeconnect')->pluck('site_id', 'id');
        if ($deviceSite->isEmpty()) {
            return [];
        }

        $active = DeviceAlarm::whereNull('cleared_at')->whereIn('device_id', $deviceSite->keys())
            ->get(['device_id', 'alarm_id', 'description']);

        // A site whose SD-WAN EDGE is unreachable is dark — a circuit's gateway ping can
        // still answer (the ISP head-end), falsely reading "up". If we can't even reach
        // the appliance, we cannot confirm the site has internet: flag every circuit.
        $edgeDownSites = $active->where('alarm_id', 'device-unreachable')
            ->map(fn (DeviceAlarm $a) => $deviceSite[$a->device_id] ?? null)->filter()->unique()->flip();

        // Local-transport alarms (gateway / IP-SLA) → the specific circuit they ride.
        $transport = $active->filter(fn (DeviceAlarm $a) => AlarmCircuitResolver::isLocalTransport($a->alarm_id, (string) $a->description));

        $out = [];
        foreach ($circuits as $c) {
            if ($c->monitoring_enabled && $c->site_id !== null && $edgeDownSites->has($c->site_id)) {
                $out[$c->id] = 'SD-WAN edge unreachable';
            }
        }

        if ($transport->isNotEmpty()) {
            $nextHops = DeviceNextHop::whereIn('device_id', $transport->pluck('device_id')->unique())->get()->groupBy('device_id');
            $circuitsBySite = $circuits->groupBy('site_id');
            $resolver = new AlarmCircuitResolver;
            foreach ($transport as $a) {
                $siteId = $deviceSite[$a->device_id] ?? null;
                $siteCircuits = $siteId !== null ? ($circuitsBySite[$siteId] ?? collect()) : collect();
                $c = $resolver->resolve($a->alarm_id, (string) $a->description, $siteCircuits, $nextHops[$a->device_id] ?? collect());
                if ($c && $c->monitoring_enabled && ! isset($out[$c->id])) {
                    $out[$c->id] = AlarmCircuitResolver::transportReason($a->alarm_id);
                }
            }
        }

        return $out;
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
