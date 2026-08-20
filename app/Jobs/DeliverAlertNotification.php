<?php

namespace App\Jobs;

use App\Services\AlertNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans an alert notification out to every channel OFF the polling loop.
 *
 * The alert observers fire inside the poller sweeps; delivering SMTP/webhook inline
 * meant a slow mail server or webhook blocked the sweep — worst during a mass-alarm
 * outage when dozens of alerts fire at once, inflating the very sweep that is already
 * longest and tripping the supervisor's heartbeat kill mid-notification. Queuing it
 * hands the work to the queue worker so the poller returns immediately.
 */
class DeliverAlertNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A failed send is logged, never retried into a loop. */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $event,
        public string $severity,
        public string $subject,
        public string $body,
        public array $context = [],
        public ?int $deviceId = null,
        public ?int $siteId = null,
    ) {
    }

    public function handle(): void
    {
        AlertNotifier::deliverNow(
            $this->event, $this->severity, $this->subject, $this->body,
            $this->context, $this->deviceId, $this->siteId,
        );
    }
}
