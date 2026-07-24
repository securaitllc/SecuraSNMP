<?php

namespace App\Services;

use App\Mail\AlertMail;
use App\Services\MailConfigurator;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Support\WebhookUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Fans an alert open/resolved event out to every enabled notification channel
 * whose minimum severity it meets, unless an active maintenance window covers
 * the affected device or site. Every attempt is recorded in notification_logs.
 */
class AlertNotifier
{
    private const SEVERITY_RANK = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /**
     * @param  'open'|'resolved'  $event
     * @param  'info'|'warning'|'critical'  $severity
     * @param  array<string, mixed>  $context
     */
    public static function dispatch(
        string $event,
        string $severity,
        string $subject,
        string $body,
        array $context = [],
        ?int $deviceId = null,
        ?int $siteId = null,
    ): void {
        try {
            if (MaintenanceWindow::suppresses($deviceId, $siteId)) {
                NotificationLog::create([
                    'notification_channel_id' => null,
                    'event' => $event,
                    'severity' => $severity,
                    'subject' => $subject,
                    'body' => $body,
                    'status' => 'suppressed',
                    'context' => $context,
                ]);

                return;
            }

            $threshold = self::SEVERITY_RANK[$severity] ?? 1;

            $channels = NotificationChannel::where('enabled', true)->get()
                ->filter(fn (NotificationChannel $c) => (self::SEVERITY_RANK[$c->min_severity] ?? 1) <= $threshold);

            foreach ($channels as $channel) {
                self::deliver($channel, $event, $severity, $subject, $body, $context);
            }
        } catch (Throwable $e) {
            // Notification delivery must never break the monitoring loop.
            Log::error('AlertNotifier failed: '.$e->getMessage());
        }
    }

    /**
     * Apply the UI-managed SMTP settings, then send the branded alert email.
     * Split out so both real alerts and the channel test go through it.
     *
     * @param  array<string, mixed>  $context
     */
    private static function sendEmail(
        NotificationChannel $channel,
        string $event,
        string $severity,
        string $subject,
        string $body,
        array $context,
    ): void {
        MailConfigurator::apply();
        Mail::to($channel->config['email'] ?? '')
            ->send(new AlertMail($event, $severity, $subject, $body, $context));
    }

    /**
     * A Microsoft Teams MessageCard (legacy connector / incoming-webhook format).
     * Body lines "Label: value" render as facts; a bare line becomes text.
     *
     * @return array<string, mixed>
     */
    private static function teamsCard(string $severity, string $subject, string $body): array
    {
        $color = ['critical' => 'D13438', 'warning' => 'D97706'][$severity] ?? '2563EB';

        $facts = [];
        $notes = [];
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([^:]{1,40}):\s*(.+)$/', $line, $m)) {
                $facts[] = ['name' => $m[1], 'value' => $m[2]];
            } else {
                $notes[] = $line;
            }
        }

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $subject,
            'themeColor' => $color,
            'title' => $subject,
            'text' => implode("\n\n", $notes),
            'sections' => $facts === [] ? [] : [['facts' => $facts]],
        ];
    }

    /** Send a canned test message to one channel and return the log row. */
    public static function test(NotificationChannel $channel): NotificationLog
    {
        return self::deliver(
            $channel,
            'open',
            'info',
            'Nodus test notification',
            "This is a test notification from Nodus for channel \"{$channel->name}\".",
            ['type' => 'test'],
        );
    }

    /**
     * POST to an outbound webhook with SSRF protection re-applied at send time.
     * The save-time guard is not enough: DNS can rebind between save and send,
     * and a redirect can bounce the request to an internal address. So we re-vet
     * the URL here and refuse to follow redirects.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function postWebhook(string $url, array $payload): void
    {
        if (! WebhookUrl::isSafe($url)) {
            throw new \RuntimeException('Webhook URL failed the SSRF safety check at send time.');
        }

        Http::timeout(5)->withoutRedirecting()->post($url, $payload)->throw();
    }

    private static function deliver(
        NotificationChannel $channel,
        string $event,
        string $severity,
        string $subject,
        string $body,
        array $context,
    ): NotificationLog {
        $status = 'sent';
        $error = null;

        try {
            match ($channel->type) {
                'email' => self::sendEmail($channel, $event, $severity, $subject, $body, $context),
                'slack' => self::postWebhook($channel->config['webhook_url'] ?? '', ['text' => "*{$subject}*\n{$body}"]),
                'teams' => self::postWebhook($channel->config['webhook_url'] ?? '', self::teamsCard($severity, $subject, $body)),
                'webhook' => self::postWebhook($channel->config['url'] ?? '', [
                    'event' => $event,
                    'severity' => $severity,
                    'subject' => $subject,
                    'body' => $body,
                    'context' => $context,
                ]),
                default => throw new \RuntimeException("Unknown channel type {$channel->type}"),
            };
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        return NotificationLog::create([
            'notification_channel_id' => $channel->id,
            'event' => $event,
            'severity' => $severity,
            'subject' => $subject,
            'body' => $body,
            'status' => $status,
            'error' => $error,
            'context' => $context,
        ]);
    }
}
