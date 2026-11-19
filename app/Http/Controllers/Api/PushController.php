<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A user's own alarm-push subscriptions (phones that opted in). Nothing here is
 * org-wide: every row belongs to the signed-in user and only they can see or
 * remove it.
 */
class PushController extends Controller
{
    /** The VAPID public key the browser needs to subscribe, plus whether push is on at all. */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => PushNotifier::enabled(),
            'public_key' => PushNotifier::enabled() ? config('push.vapid.public_key') : null,
            'subscriptions' => PushSubscription::where('user_id', $request->user()->id)
                ->get(['id', 'endpoint', 'user_agent', 'min_severity', 'last_sent_at', 'created_at'])
                ->map(fn (PushSubscription $s) => [
                    'id' => $s->id,
                    // Enough for the client to recognise its own row, never the full URL.
                    'endpoint_tail' => substr($s->endpoint, -16),
                    'user_agent' => $s->user_agent,
                    'min_severity' => $s->min_severity,
                    'last_sent_at' => $s->last_sent_at,
                    'created_at' => $s->created_at,
                ]),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        if (! PushNotifier::enabled()) {
            return response()->json(['message' => 'Push is not configured on this server.'], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'min_severity' => ['sometimes', 'in:info,warning,critical'],
        ]);

        if (! PushNotifier::isPushEndpoint($data['endpoint'])) {
            return response()->json(['message' => 'Unsupported push service.'], 422);
        }

        // An endpoint is a capability URL for one browser. If another account already
        // holds it, only the device itself can move it (same shared phone, new sign-in):
        // it proves that by presenting the identical keys. Anyone else is refused.
        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $existing = PushSubscription::where('endpoint_hash', $hash)->first();
        if ($existing && $existing->user_id !== $request->user()->id
            && ! (hash_equals($existing->p256dh, $data['keys']['p256dh']) && hash_equals($existing->auth, $data['keys']['auth']))) {
            return response()->json(['message' => 'This device is registered to another account.'], 409);
        }

        $sub = PushSubscription::updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'min_severity' => $data['min_severity'] ?? 'critical',
                'failures' => 0,
            ],
        );

        return response()->json(['id' => $sub->id, 'min_severity' => $sub->min_severity], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(null, 204);
    }

    /** Send a test notification to this user's subscription for the given endpoint. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $sub = PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->first();

        if (! $sub) {
            return response()->json(['message' => 'This device is not subscribed.'], 404);
        }

        $ok = PushNotifier::send($sub, PushNotifier::payload(
            'test', 'info', 'Nodus push test', 'Alarm notifications reach this device.', ['alarm_id' => 'test'],
        ));

        return response()->json(['sent' => $ok], $ok ? 200 : 502);
    }
}
