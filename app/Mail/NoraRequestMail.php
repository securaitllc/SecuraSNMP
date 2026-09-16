<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One plain-text message to Lumen NORA.
 *
 * The Message-ID is ours, generated before sending and stored with the request, so
 * NORA's threaded reply — which carries the ticket number — can be matched back to
 * the circuit by In-Reply-To. Without that the thread is just mail.
 */
class NoraRequestMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $messageId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('nora.from_address'), config('nora.from_name')),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.nora-request');
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->messageId);
    }
}
