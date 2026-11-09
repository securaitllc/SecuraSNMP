<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The professional HTML alert email. Renders the alert description as a clean
 * details block with a severity-coloured header, and distinguishes a firing
 * alert from a resolved one.
 */
class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'open'|'resolved'  $event
     * @param  'info'|'warning'|'critical'  $severity
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $event,
        public string $severity,
        public string $subjectLine,
        public string $description,
        public array $context = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $resolved = $this->event === 'resolved';

        // Colour the header by state: resolved is always green; otherwise by
        // severity. Keeps the "all-clear" visually distinct from a firing alert.
        $accent = $resolved ? '#16a34a' : match ($this->severity) {
            'critical' => '#dc2626',
            'warning' => '#d97706',
            default => '#2563eb',
        };

        return new Content(
            view: 'emails.alert',
            with: [
                'resolved' => $resolved,
                'accent' => $accent,
                'statusLabel' => $resolved ? 'Resolved' : 'Alert firing',
                'severityLabel' => strtoupper($this->severity),
                'rows' => $this->parseDescription($this->description),
                'rawDescription' => $this->description,
                'sentAt' => now()->format('M j, Y g:i A T'),
            ],
        );
    }

    /**
     * Split a "Label: value" description block into rows for the details table.
     * A line without a colon becomes a full-width note row.
     *
     * @return list<array{label: ?string, value: string}>
     */
    private function parseDescription(string $description): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($description)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([^:]{1,40}):\s*(.*)$/', $line, $m) && $m[2] !== '') {
                $rows[] = ['label' => $m[1], 'value' => $m[2]];
            } else {
                $rows[] = ['label' => null, 'value' => $line];
            }
        }

        return $rows;
    }
}
