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
     * The payload for a Teams destination.
     *
     * Microsoft retired Office 365 connectors, so a tenant's "Incoming Webhook" URL
     * stops accepting posts and the replacement is a Power Automate workflow or an
     * Azure Function fronting the channel. Those expect the Bot-Framework envelope
     * with an Adaptive Card attachment, NOT the old MessageCard — posting a
     * MessageCard to a workflow gets accepted and then renders as nothing.
     *
     * The two are told apart by the host: only a genuine connector URL keeps the
     * legacy format, so an existing connector that still works is not broken by the
     * change and a new workflow URL needs no extra configuration.
     *
     * @return array<string, mixed>
     */
    private static function teamsPayload(string $url, string $severity, string $subject, string $body): array
    {
        [$facts, $notes] = self::splitBody($body);

        return self::isLegacyConnector($url)
            ? self::teamsMessageCard($severity, $subject, $facts, $notes)
            : self::teamsAdaptiveCard($severity, $subject, $facts, $notes);
    }

    /** A retired-but-maybe-still-alive Office 365 connector endpoint. */
    private static function isLegacyConnector(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_ends_with($host, '.webhook.office.com')
            || str_ends_with($host, '.office.com')
            || str_ends_with($host, 'outlook.office365.com');
    }

    /**
     * Body lines "Label: value" become facts; bare lines stay prose.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: list<string>}
     */
    private static function splitBody(string $body): array
    {
        $facts = [];
        $notes = [];

        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([^:]{1,40}):\s*(.+)$/', $line, $m)) {
                $facts[] = [$m[1], $m[2]];
            } else {
                $notes[] = $line;
            }
        }

        return [$facts, $notes];
    }

    /**
     * Bot-Framework envelope + Adaptive Card — what a Power Automate workflow or an
     * Azure Function relaying into Teams expects.
     *
     * @param  list<array{0: string, 1: string}>  $facts
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private static function teamsAdaptiveCard(string $severity, string $subject, array $facts, array $notes): array
    {
        // Adaptive Cards take a named colour, not a hex value.
        $color = ['critical' => 'Attention', 'warning' => 'Warning'][$severity] ?? 'Accent';

        $blocks = [[
            'type' => 'TextBlock',
            'text' => $subject,
            'weight' => 'Bolder',
            'size' => 'Medium',
            'color' => $color,
            'wrap' => true,
        ]];

        if ($facts !== []) {
            $blocks[] = [
                'type' => 'FactSet',
                'facts' => array_map(fn (array $f) => ['title' => $f[0], 'value' => $f[1]], $facts),
            ];
        }

        if ($notes !== []) {
            $blocks[] = ['type' => 'TextBlock', 'text' => implode("\n\n", $notes), 'wrap' => true];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $blocks,
                ],
            ]],
        ];
    }

    /**
     * The legacy MessageCard an Office 365 connector understands.
     *
     * @param  list<array{0: string, 1: string}>  $facts
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private static function teamsMessageCard(string $severity, string $subject, array $facts, array $notes): array
    {
        $color = ['critical' => 'D13438', 'warning' => 'D97706'][$severity] ?? '2563EB';

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $subject,
            'themeColor' => $color,
            'title' => $subject,
            'text' => implode("\n\n", $notes),
            'sections' => $facts === [] ? [] : [['facts' => array_map(
                fn (array $f) => ['name' => $f[0], 'value' => $f[1]],
                $facts,
            )]],
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
                'teams' => self::postWebhook(
                    $channel->config['webhook_url'] ?? '',
                    self::teamsPayload($channel->config['webhook_url'] ?? '', $severity, $subject, $body),
                ),
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
