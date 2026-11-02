<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Delivers alarm notifications to phones that opted in (Web Push / VAPID).
 *
 * Runs beside the org-wide notification channels in AlertNotifier::deliverNow,
 * filtered per subscription by its own minimum severity. A push service that
 * answers 404/410 is a device that unsubscribed behind our back, so the row is
 * removed; any other failure is counted and the row is dropped after a run of
 * them, so a dead phone never keeps costing sends.
 */
class PushNotifier
{
    private const RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    private const MAX_FAILURES = 10;

    /**
     * Browsers only ever hand out endpoints on their vendor's push service. Anything
     * else is a request to make this server POST somewhere of the caller's choosing,
     * so it is refused at subscribe time AND again at send time.
     */
    private const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        'api.push.apple.com',
    ];

    private const PUSH_HOST_SUFFIXES = ['.notify.windows.com', '.push.apple.com', '.push.services.mozilla.com'];

    public static function isPushEndpoint(string $url): bool
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! \App\Support\WebhookUrl::isSafe($url)) {
            return false;
        }

        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if (in_array($host, self::PUSH_HOSTS, true)) {
            return true;
        }
        foreach (self::PUSH_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @var (callable(PushSubscription, string): array{ok: bool, status: ?int})|null */
    private static $sender = null;

    public static function enabled(): bool
    {
        return (bool) config('push.vapid.private_key') && (bool) config('push.vapid.public_key');
    }

    /**
     * Swap the transport (tests). The callable gets the subscription and the JSON
     * payload and returns ['ok' => bool, 'status' => int|null].
     */
    public static function fakeSender(?callable $sender): void
    {
        self::$sender = $sender;
    }

    /** Fan an alarm event out to every subscription whose threshold it meets. */
    public static function fanOut(string $event, string $severity, string $subject, string $body, array $context = []): int
    {
        if (! self::enabled() && self::$sender === null) {
            return 0;
        }

        $rank = self::RANK[$severity] ?? 1;
        // A deactivated account must stop hearing about the network at once.
        $subs = PushSubscription::query()
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (PushSubscription $s) => (self::RANK[$s->min_severity] ?? 1) <= $rank);

        if ($subs->isEmpty()) {
            return 0;
        }

        $payload = self::payload($event, $severity, $subject, $body, $context);
        $sent = 0;
        foreach ($subs as $sub) {
            if (self::send($sub, $payload)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** One subscription, one payload. True when the push service accepted it. */
    public static function send(PushSubscription $sub, string $payload): bool
    {
        try {
            $result = self::$sender
                ? (self::$sender)($sub, $payload)
                : self::deliver($sub, $payload);
        } catch (Throwable $e) {
            Log::warning('Push send failed: '.$e->getMessage());
            $result = ['ok' => false, 'status' => null];
        }

        if ($result['ok']) {
            $sub->forceFill(['last_sent_at' => now(), 'failures' => 0])->saveQuietly();

            return true;
        }

        // Gone / not found = the browser dropped the subscription. Anything else:
        // count it, and give up on the row after enough in a row.
        if (in_array($result['status'], [404, 410], true) || $sub->failures + 1 >= self::MAX_FAILURES) {
            $sub->delete();
        } else {
            $sub->increment('failures');
        }

        return false;
    }

    public static function payload(string $event, string $severity, string $subject, string $body, array $context = []): string
    {
        return json_encode([
            'title' => $subject,
            'body' => $body,
            'severity' => $severity,
            'event' => $event,
            // One notification per alarm on the phone: a resolve replaces its open.
            'tag' => $context['alarm_id'] ?? ($context['circuit_id'] ?? ($context['device_id'] ?? $subject)),
            'url' => '/m/alarms',
            'sent_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** @return array{ok: bool, status: ?int} */
    private static function deliver(PushSubscription $sub, string $payload): array
    {
        if (! self::isPushEndpoint($sub->endpoint)) {
            // Stored before the allow-list existed, or tampered: never send, drop it.
            return ['ok' => false, 'status' => 410];
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('push.vapid.subject'),
                'publicKey' => config('push.vapid.public_key'),
                'privateKey' => config('push.vapid.private_key'),
            ],
        ], ['TTL' => config('push.ttl'), 'urgency' => 'high'], null, ['allow_redirects' => false, 'timeout' => 10]);

        $subscription = Subscription::create([
            'endpoint' => $sub->endpoint,
            'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
        ]);

        $report = $webPush->sendOneNotification($subscription, $payload);
        $status = $report->getResponse()?->getStatusCode();

        return ['ok' => $report->isSuccess(), 'status' => $status];
    }
}
