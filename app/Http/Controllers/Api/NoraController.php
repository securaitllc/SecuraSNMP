<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NoraRequestMail;
use App\Models\Circuit;
use App\Models\CircuitIspTicket;
use App\Models\NoraRequest;
use App\Services\Lumen\NoraComposer;
use App\Services\Lumen\NoraGates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 1 of carrier ticketing via Lumen NORA: Nodus writes the email, a human reads
 * it and clicks Send. The gates warn; they do not block. When NORA replies with a
 * ticket number the NOC enters it against the circuit as they do today — the inbox
 * reader that does that automatically is Phase 2.
 */
class NoraController extends Controller
{
    /** The email as it would be sent, plus the gate warnings, for review. */
    public function preview(Request $request, Circuit $circuit, NoraComposer $composer, NoraGates $gates): JsonResponse
    {
        if (! NoraComposer::handles($circuit)) {
            return response()->json(['message' => 'This circuit\'s carrier is not handled by NORA.'], 422);
        }
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(NoraComposer::KINDS)],
            'impact' => ['nullable', Rule::in(NoraComposer::IMPACTS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'enabled' => (bool) config('nora.enabled'),
            'to' => config('nora.address'),
            'from' => config('nora.from_address'),
            'gates' => $gates->evaluate($circuit),
            ...$composer->compose($circuit, $data['kind'] ?? 'open_ticket', $data['impact'] ?? null, $data['note'] ?? null),
        ]);
    }

    /** Send it. The body may have been edited in the preview; what is sent is what is stored. */
    public function send(Request $request, Circuit $circuit, NoraComposer $composer): JsonResponse
    {
        if (! config('nora.enabled')) {
            return response()->json(['message' => 'NORA sending is disabled (NORA_ENABLED). The NOC mailbox must be registered with Lumen SMCM first.'], 409);
        }
        if (! NoraComposer::handles($circuit)) {
            return response()->json(['message' => 'This circuit\'s carrier is not handled by NORA.'], 422);
        }
        $data = $request->validate([
            'kind' => ['required', Rule::in(NoraComposer::KINDS)],
            'impact' => ['nullable', Rule::in(NoraComposer::IMPACTS)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:8000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $composed = $composer->compose($circuit, $data['kind'], $data['impact'] ?? null, $data['note'] ?? null);
        $subject = trim($data['subject'] ?? '') ?: $composed['subject'];
        $body = trim($data['body'] ?? '') ?: $composed['body'];
        // Symfony wants the addr-spec BARE and adds the angle brackets itself; handing
        // it "<id@host>" throws RfcComplianceException, which would have 500'd the very
        // first send the moment NORA_ENABLED was flipped on. Store the wire form (with
        // brackets) because that is how it comes back in NORA's In-Reply-To.
        $addrSpec = 'nodus-'.Str::uuid().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'nodus.local');
        $messageId = '<'.$addrSpec.'>';

        Mail::to(config('nora.address'))->send(new NoraRequestMail($subject, $body, $addrSpec));

        $openTicket = $circuit->ispTickets()->whereNull('closed_at')->latest('opened_at')->first();

        $req = NoraRequest::create([
            'circuit_id' => $circuit->id,
            'circuit_isp_ticket_id' => $openTicket?->id,
            'kind' => $data['kind'],
            'message_id' => $messageId,
            'subject' => $subject,
            'body' => $body,
            'impact' => $composed['impact'],
            'sent_by' => $request->user()?->name,
            'sent_at' => now(),
        ]);

        return response()->json($req, 201);
    }

    /** Every NORA email about this circuit, newest first. */
    public function history(Circuit $circuit): JsonResponse
    {
        return response()->json(['data' => $circuit->noraRequests()->limit(50)->get()]);
    }

    /** Record the ticket number NORA replied with, against the request and the circuit. */
    public function recordTicket(Request $request, Circuit $circuit, NoraRequest $noraRequest): JsonResponse
    {
        abort_unless($noraRequest->circuit_id === $circuit->id, 404);
        $data = $request->validate(['ticket_number' => ['required', 'string', 'max:64']]);

        $noraRequest->update(['ticket_number' => $data['ticket_number'], 'replied_at' => $noraRequest->replied_at ?? now()]);

        // Same trail the manual path writes, so the ISP-ticket history stays one list.
        if ($circuit->isp_ticket !== $data['ticket_number']) {
            $circuit->update(['isp_ticket' => $data['ticket_number']]);
            $ticket = CircuitIspTicket::create([
                'circuit_id' => $circuit->id,
                'ticket_number' => $data['ticket_number'],
                'reason' => 'nora',
                'note' => 'Opened via Lumen NORA ('.$noraRequest->kind.')',
                'opened_at' => now(),
                'opened_by' => $request->user()?->name,
            ]);
            $noraRequest->update(['circuit_isp_ticket_id' => $ticket->id]);
        }

        return response()->json($noraRequest->fresh());
    }
}
