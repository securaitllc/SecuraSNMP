<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CircuitRequest;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitRenewal;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Services\AlarmCircuitResolver;
use App\Services\CircuitBandwidth;
use App\Services\CircuitDeduplicator;
use App\Services\CircuitOutageResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

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

    /**
     * One live read of the octet counters on this circuit's WAN port.
     *
     * Mirrors the device page's live ICMP / CPU probes: a bounded SNMP get, no DB
     * write, safe to call on a timer. It returns RAW COUNTERS rather than a rate —
     * a rate needs two reads, and the caller already holds the previous one, so the
     * client differences successive samples. That keeps the endpoint stateless, and
     * means two operators watching the same circuit cannot disturb each other.
     */
    public function bandwidthLive(Circuit $circuit): JsonResponse
    {
        $bw = (new CircuitBandwidth)->for($circuit);
        $miss = fn (string $why) => response()->json([
            'ok' => false, 'reason' => $why, 'at' => now()->toIso8601String(),
        ]);

        if (! $bw['mapped']) {
            return $miss($bw['reason'] ?? 'not measured');
        }

        $device = Device::where('site_id', $circuit->site_id)
            ->whereIn('role', ['edgeconnect', 'firewall'])
            ->whereHas('interfaces', fn ($q) => $q->whereRaw('LOWER(if_name) = ?', [$bw['wan_interface']]))
            ->first();
        $iface = $device?->interfaces()->whereRaw('LOWER(if_name) = ?', [$bw['wan_interface']])->first();

        if (! $device || ! $iface || ! $iface->if_index) {
            return $miss('WAN port is not polled on the appliance');
        }
        if (! $device->snmp_community && ! $device->snmp_v3_username) {
            return $miss('no SNMP credentials on the appliance');
        }

        // 64-bit counters: a 1 Gbps port wraps the 32-bit ones in ~34 seconds, which at
        // a few-second poll would surface as wild spikes or negative rates.
        $oids = [
            'in' => '.1.3.6.1.2.1.31.1.1.1.6.'.$iface->if_index,   // ifHCInOctets
            'out' => '.1.3.6.1.2.1.31.1.1.1.10.'.$iface->if_index, // ifHCOutOctets
        ];

        $counters = [];
        foreach ($oids as $dir => $oid) {
            if (! preg_match('/(\d+)\s*$/', trim($this->snmpGet($device, $oid)), $hit)) {
                return $miss('the appliance did not answer for that port');
            }
            $counters[$dir] = (float) $hit[1];
        }

        return response()->json([
            'ok' => true,
            'in_octets' => $counters['in'],
            'out_octets' => $counters['out'],
            'contract_down_mbps' => $circuit->contract_down_mbps,
            'contract_up_mbps' => $circuit->contract_up_mbps,
            'wan_interface' => $bw['wan_interface'],
            'at' => now()->toIso8601String(),
        ]);
    }

    /** Bounded snmpget (-t 2 -r 1, hard 6s kill) for the live bandwidth probe. */
    private function snmpGet(Device $device, string $oid): string
    {
        $cmd = $device->snmp_version === 'v3'
            ? ['snmpget', '-Oqv', '-t', '2', '-r', '1', '-v3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key, $device->ip_address, $oid]
            : ['snmpget', '-Oqv', '-t', '2', '-r', '1', '-v2c', '-c', (string) $device->snmp_community, $device->ip_address, $oid];

        $p = new Process($cmd);
        $p->setTimeout(6);   // a live graph must never hang the request
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : '';
    }

    /** Throughput history for the circuit's bandwidth chart. */
    public function bandwidth(Request $request, Circuit $circuit): JsonResponse
    {
        $hours = match ($request->query('range')) {
            '1h' => 1, '6h' => 6, '7d' => 168, '30d' => 720, default => 24,
        };

        return response()->json((new CircuitBandwidth)->history($circuit, $hours));
    }

    public function index(Request $request): JsonResponse
    {
        $circuits = Circuit::query()->with(['ispProvider', 'sharedSites:id,name'])
            ->when($request->query('site_id'), fn ($query, $siteId) => $query->where('site_id', $siteId))
            ->orderBy('isp_name')
            ->get();

        $transportDegraded = $this->transportDegraded($circuits);

        // The true outage envelope + flapping state, computed only for circuits that are
        // actually impacted (down, transport-degraded, or with an open ping alert) so the
        // list stays cheap. This is what fixes "5h vs since-yesterday": the envelope is the
        // earliest still-open signal across every source, not the loudest alarm's clock.
        $impactedIds = $circuits->filter(fn (Circuit $c) => $c->status === 'down' || isset($transportDegraded[$c->id]))
            ->pluck('id')
            ->merge(CircuitAlert::whereNull('ended_at')->pluck('circuit_id'))
            ->unique();
        $impacted = $circuits->whereIn('id', $impactedIds);
        $outages = $impacted->isNotEmpty() ? (new CircuitOutageResolver)->summarize($impacted) : [];

        // Throughput attributed from each circuit's EdgeConnect WAN port, as a share
        // of the CONTRACT (not the 1 Gbps physical port). Batched: 250 circuits would
        // otherwise be hundreds of queries.
        $bandwidth = (new CircuitBandwidth)->forMany($circuits);

        $circuits->each(function (Circuit $c) use ($transportDegraded, $outages, $bandwidth) {
            $c->setAttribute('shared_site_ids', $c->sharedSites->pluck('id')->all());
            $c->setAttribute('contract_status', $c->contractStatus());
            $c->setAttribute('days_to_expiry', $c->daysToExpiry());
            // The appliance's authoritative view: an SD-WAN transport can be failing
            // (IP-SLA / gateway alarm) while the gateway ICMP ping still answers 0% loss.
            $c->setAttribute('transport_degraded', isset($transportDegraded[$c->id]));
            $c->setAttribute('transport_reason', $transportDegraded[$c->id] ?? null);
            $c->setAttribute('outage', $outages[$c->id]['outage'] ?? null);
            $c->setAttribute('bounce', $outages[$c->id]['bounce'] ?? null);
            $c->setAttribute('bandwidth', $bandwidth[$c->id] ?? null);
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
     * @param  Collection<int,Circuit>  $circuits
     * @return array<int,string> circuit id => reason
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
            ? Carbon::parse($data['new_end_date'])
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
            ->map(fn (CircuitRenewal $r) => [
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

    /**
     * Log (or clear) the current ISP dispatch ticket on the circuit itself — a case ref
     * that shows on the circuit's alarms whether it's hard-down (open CircuitAlert) or
     * only transport-degraded (IP-SLA / tunnel down, ping still answering, no outage row).
     */
    public function setIspTicket(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate(['isp_ticket' => ['nullable', 'string', 'max:100']]);
        $circuit->update(['isp_ticket' => $data['isp_ticket'] ?: null]);

        return response()->json(['isp_ticket' => $circuit->isp_ticket]);
    }

    /**
     * Current ISP field-dispatch ETA (+ note) on the circuit — the promised time a tech
     * is coming. Circuit-level like the ISP ticket, so it attaches to an SD-WAN transport
     * degrade with no open outage, and the dashboard + circuits page share one value.
     */
    public function setDispatch(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate([
            'dispatch_at' => ['nullable', 'date'],
            // The end of the arrival window. ISPs commit to a window ("tomorrow
            // 08:00–12:00"), rarely to a time — and often give one with no ticket at
            // all, which is why this endpoint never asks for a ticket number.
            'dispatch_end_at' => ['nullable', 'date', 'after_or_equal:dispatch_at'],
            'dispatch_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $at = $data['dispatch_at'] ?? null;
        $end = $at ? ($data['dispatch_end_at'] ?? null) : null;
        $note = $at ? (($data['dispatch_note'] ?? null) ?: null) : null;

        $circuit->update([
            'dispatch_at' => $at,
            'dispatch_end_at' => $end,
            'dispatch_note' => $note,
        ]);

        // Mirror onto the open outage, when there is one, so the outage record carries
        // the same commitment the circuit does and a missed dispatch stays provable
        // after the outage closes.
        $circuit->alerts()->whereNull('ended_at')->latest('started_at')->first()?->update([
            'dispatch_at' => $at,
            'dispatch_end_at' => $end,
            'dispatch_note' => $note,
            'dispatch_by' => $at ? $request->user()->id : null,
        ]);

        return response()->json([
            'dispatch_at' => optional($circuit->dispatch_at)->toIso8601String(),
            'dispatch_end_at' => optional($circuit->dispatch_end_at)->toIso8601String(),
            'dispatch_note' => $circuit->dispatch_note,
        ]);
    }

    /**
     * The full outage story for one circuit in a single place: every related alert
     * (circuit ping, EdgeConnect gateway / IP-SLA, next-hop) time-sorted, plus the true
     * outage envelope and the flapping state — so the NOC stops hopping across the
     * circuit, device, next-hop and tunnel views to reconstruct one outage.
     */
    public function history(Request $request, Circuit $circuit): JsonResponse
    {
        $days = (int) $request->query('days', 7);

        return response()->json([
            'circuit' => [
                'id' => $circuit->id,
                'circuit_id' => $circuit->circuit_id,
                'isp_name' => $circuit->isp_name,
                'wan_interface' => $circuit->wan_interface,
                'status' => $circuit->status,
            ],
            ...(new CircuitOutageResolver)->history($circuit, max(1, min(90, $days))),
        ]);
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
