<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CircuitAlertRequest;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CircuitAlertController extends Controller
{
    public function index(Circuit $circuit): JsonResponse
    {
        $alerts = $circuit->alerts()
            ->with(['acknowledgedBy:id,name', 'clearedBy:id,name', 'dispatchBy:id,name'])
            ->orderByDesc('started_at')
            ->get();

        // Expose the actor names under explicit keys so the loaded relations don't
        // collide with the *_by integer columns on toArray().
        return response()->json($alerts->map(fn (CircuitAlert $a) => [
            ...$a->toArray(),
            'acknowledged_by' => $a->acknowledged_by,
            'acknowledged_by_name' => optional($a->acknowledgedBy)->name,
            'cleared_by' => $a->cleared_by,
            'cleared_by_name' => optional($a->clearedBy)->name,
            'dispatch_by' => $a->dispatch_by,
            'dispatch_by_name' => optional($a->dispatchBy)->name,
        ]));
    }

    public function update(CircuitAlertRequest $request, Circuit $circuit, CircuitAlert $alert): JsonResponse
    {
        abort_if($alert->circuit_id !== $circuit->id, 404);

        $alert->update($request->validated());

        return response()->json($alert);
    }

    /** Record the ISP-provided ticket number on the circuit's open outage. */
    public function ticket(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate(['ticket_number' => ['nullable', 'string', 'max:100']]);

        $alert = $this->openAlert($circuit);
        $alert->update(['ticket_number' => $data['ticket_number'] ?: null]);

        return response()->json($alert->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }

    /** Acknowledge the circuit outage with an investigation note (e.g. "ISP dispatched"). */
    public function acknowledge(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $alert = $this->openAlert($circuit);
        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'ack_note' => $data['note'] ?? null,
        ]);

        return response()->json($alert->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }

    /** Record (or update/clear) the ISP-scheduled dispatch date/time for the outage,
     *  with the operator who logged it — for the record and accountability. */
    public function dispatch(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate([
            'dispatch_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert = $this->openAlert($circuit);
        $alert->update([
            'dispatch_at' => $data['dispatch_at'] ?? null,
            'dispatch_note' => $data['note'] ?? null,
            'dispatch_by' => ($data['dispatch_at'] ?? null) ? $request->user()->id : null,
        ]);

        return response()->json($alert->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name', 'dispatchBy:id,name']));
    }

    /** Manually clear the circuit outage (false positive / maintenance) with a note.
     *  Marked cleared_manually so the monitor won't re-alert until a real flap. */
    public function clear(Request $request, Circuit $circuit): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $alert = $this->openAlert($circuit);
        $alert->update([
            'ended_at' => now(),
            'cleared_by' => $request->user()->id,
            'clear_note' => $data['note'] ?? null,
            'cleared_manually' => true,
        ]);

        return response()->json($alert->fresh()->load(['acknowledgedBy:id,name', 'clearedBy:id,name']));
    }

    /**
     * The circuit's current open outage. If the circuit is down but has no open
     * alert row yet (e.g. seeded down, or created down manually), open one now so
     * a NOC can attach a ticket immediately.
     */
    private function openAlert(Circuit $circuit): CircuitAlert
    {
        return $circuit->alerts()->whereNull('ended_at')->latest('started_at')->first()
            ?? $circuit->alerts()->create(['started_at' => $circuit->last_checked_at ?? now()]);
    }
}
