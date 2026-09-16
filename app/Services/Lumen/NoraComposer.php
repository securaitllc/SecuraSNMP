<?php

namespace App\Services\Lumen;

use App\Models\Circuit;
use Carbon\CarbonInterface;

/**
 * The email Nodus sends to Lumen NORA, built from what the app already knows.
 *
 * NORA asks for five things and asks follow-ups when any is missing: circuit ID, an
 * existing ticket number, symptoms with onset, whether it is still happening, and
 * business impact. Every one of those is already on the circuit or its alert. The
 * point of composing it here is that the NOC never retypes a circuit ID onto a call
 * again — and that the exact text sent is on record.
 *
 * Plain text on purpose. An email agent parses prose; markup only gets in the way.
 */
class NoraComposer
{
    public const KINDS = [
        'open_ticket', 'check_service', 'status', 'escalate', 'close',
        'add_note', 'open_tickets', 'utilization', 'maintenance',
    ];

    public const IMPACTS = ['low', 'medium', 'high', 'critical'];

    /**
     * @return array{subject: string, body: string, impact: string}
     */
    public function compose(Circuit $circuit, string $kind, ?string $impact = null, ?string $note = null): array
    {
        $circuit->loadMissing('site');
        $impact ??= self::defaultImpact($circuit);
        $ref = $this->reference($circuit);
        $alert = $circuit->alerts()->whereNull('ended_at')->latest('started_at')->first();
        $since = $alert?->started_at ?? $circuit->degraded_since;
        $ongoing = $circuit->status === 'down' || (int) ($circuit->loss_polls_pct ?? 0) > 0;

        $subject = match ($kind) {
            'open_ticket' => "Repair ticket request — {$ref}",
            'check_service' => "Service check request — {$ref}",
            'status' => 'Ticket status — '.($circuit->isp_ticket ?: $ref),
            'escalate' => 'Escalation request — '.($circuit->isp_ticket ?: $ref),
            'close' => 'Ticket closure — '.($circuit->isp_ticket ?: $ref),
            'add_note' => 'Ticket note — '.($circuit->isp_ticket ?: $ref),
            'open_tickets' => "Open tickets — {$ref}",
            'utilization' => "Traffic utilization — {$ref}",
            'maintenance' => "Maintenance inquiry — {$ref}",
        };

        $lines = [];
        $lines[] = match ($kind) {
            'open_ticket' => 'Please open a repair ticket for the following service.',
            'check_service' => 'Please run service checks / diagnostics on the following service and share the results. Do not open a ticket yet.',
            'status' => 'Please provide the current status of the ticket below.',
            'escalate' => 'Please escalate the ticket below.',
            'close' => 'The issue below is resolved. Please close the ticket.',
            'add_note' => 'Please add the note below to the ticket.',
            'open_tickets' => 'Please list any open tickets for the service below.',
            'utilization' => 'Please report traffic utilization for the service below — current, peak and average over the last 3 hours, 24 hours, 7 days and 30 days.',
            'maintenance' => 'Please list any maintenance windows, planned outages or GCRs affecting the service below, over the last 7 days and the next 14 days.',
        };
        $lines[] = '';
        $lines[] = "Circuit ID: {$circuit->circuit_id}";
        if ($circuit->lec_circuit_id) {
            $lines[] = "LEC circuit ID: {$circuit->lec_circuit_id}";
        }
        if ($circuit->account_number) {
            $lines[] = "Account: {$circuit->account_number}";
        }
        $lines[] = 'Existing ticket number: '.($circuit->isp_ticket ?: 'none');
        $lines[] = 'Site: '.self::siteLabel($circuit);
        if ($circuit->site?->address) {
            $lines[] = "Site address: {$circuit->site->address}";
        }
        $lines[] = '';

        if (in_array($kind, ['open_ticket', 'check_service', 'add_note'], true)) {
            $lines[] = 'Symptoms: '.$this->symptoms($circuit);
            $lines[] = 'Started: '.($since ? $this->when($since) : 'not recorded');
            $lines[] = 'Still occurring: '.($ongoing ? 'yes' : 'no');
            $lines[] = "Business impact: {$impact}";
            $lines[] = 'Monitored address: '.$circuit->monitored_ip.' (carrier gateway, ICMP from our NOC every 60s)';

            // The hop our own trace blames, in the carrier's own naming. This is the
            // thing a carrier can act on, and it used to be worked out live on a call.
            $probe = $circuit->pathProbes()->whereNotNull('worst_hop_host')->first();
            if ($probe) {
                $lines[] = "Our trace ({$probe->tool}, {$probe->cycles} probes/hop, ".$this->when($probe->ran_at).') shows loss first persisting at hop '
                    ."{$probe->worst_hop} {$probe->worst_hop_host} ({$probe->worst_hop_loss_pct}% at that hop).";
            }
            $lines[] = '';
        }

        if ($note) {
            $lines[] = 'Notes: '.trim($note);
            $lines[] = '';
        }

        $lines[] = 'Site contact: '.($circuit->site?->main_phone ?: 'see account');
        $lines[] = 'NOC contact: '.config('nora.from_name').' <'.config('nora.from_address').'>';

        return ['subject' => $subject, 'body' => implode("\n", $lines), 'impact' => $impact];
    }

    /** Hub sites carry everything behind them; a branch is one location. */
    public static function defaultImpact(Circuit $circuit): string
    {
        return ($circuit->site?->site_type === 'hub') ? 'critical' : 'medium';
    }

    /** Whether this circuit's carrier is one NORA handles. */
    public static function handles(Circuit $circuit): bool
    {
        $isp = strtolower((string) $circuit->isp_name);
        foreach ((array) config('nora.carriers') as $name) {
            if ($isp !== '' && str_contains($isp, strtolower($name))) {
                return true;
            }
        }

        return false;
    }

    private function reference(Circuit $circuit): string
    {
        $site = $circuit->site?->site_number ? " (#{$circuit->site->site_number})" : '';

        return ($circuit->circuit_id ?: 'circuit '.$circuit->id).$site;
    }

    /**
     * "#037 Lawrenceville GA" — never "#037 #037 Lawrenceville GA".
     *
     * Massey's site names already begin with the site number on this fleet, so
     * prefixing it unconditionally doubled it in the email to the carrier.
     */
    public static function siteLabel(Circuit $circuit): string
    {
        $name = trim((string) ($circuit->site?->name ?? ''));
        $num = trim((string) ($circuit->site?->site_number ?? ''));
        if ($num === '' || str_starts_with($name, '#'.$num)) {
            return $name !== '' ? $name : '—';
        }

        return trim("#{$num} {$name}");
    }

    private function symptoms(Circuit $circuit): string
    {
        if ($circuit->status === 'down') {
            return 'Service down — no response from the carrier gateway.';
        }
        $polls = (int) ($circuit->loss_polls_pct ?? 0);
        $peak = (int) ($circuit->loss_peak_pct ?? 0);
        if ($polls > 0) {
            return "Intermittent packet loss — lossy on {$polls}% of polls, peak {$peak}% loss.";
        }

        return 'Degraded service.';
    }

    private function when(CarbonInterface $t): string
    {
        return $t->copy()->timezone('America/New_York')->format('D M j, Y g:i A T');
    }
}
