<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const SUB = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'BPUBLICKEY', 'auth' => 'AUTHSECRET'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['push.vapid.public_key' => 'BTESTPUBLIC', 'push.vapid.private_key' => 'TESTPRIVATE']);
        PushNotifier::fakeSender(null);
    }

    protected function tearDown(): void
    {
        PushNotifier::fakeSender(null);
        parent::tearDown();
    }

    public function test_status_exposes_the_public_key_and_only_my_subscriptions(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        PushSubscription::create(['user_id' => $other->id, 'endpoint_hash' => 'x', 'endpoint' => 'https://p/x', 'p256dh' => 'k', 'auth' => 'a']);

        $this->actingAs($me)->getJson('/api/push')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('public_key', 'BTESTPUBLIC')
            ->assertJsonCount(0, 'subscriptions');
    }

    public function test_subscribe_stores_the_device_and_hides_its_keys(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->postJson('/api/push/subscribe', self::SUB + ['min_severity' => 'warning'])
            ->assertCreated()->assertJsonPath('min_severity', 'warning');

        $row = PushSubscription::first();
        $this->assertSame($me->id, $row->user_id);
        $this->assertSame('AUTHSECRET', $row->auth);

        $status = $this->actingAs($me)->getJson('/api/push')->json('subscriptions.0');
        $this->assertArrayNotHasKey('auth', $status);
        $this->assertArrayNotHasKey('p256dh', $status);
        $this->assertArrayNotHasKey('endpoint', $status);
    }

    /** SSRF: the server POSTs to this URL, so only real browser push services are accepted. */
    public function test_subscribe_refuses_endpoints_that_are_not_a_push_service(): void
    {
        $me = User::factory()->create();

        foreach ([
            'http://fcm.googleapis.com/fcm/send/x',          // not https
            'https://10.11.10.57:34443/api/alerts/clear',    // internal target
            'https://evil.example.com/collect',              // unknown host
            'https://fcm.googleapis.com.evil.example/x',     // suffix trick
        ] as $endpoint) {
            $this->actingAs($me)->postJson('/api/push/subscribe', ['endpoint' => $endpoint] + ['keys' => self::SUB['keys']])
                ->assertStatus(422);
        }

        $this->assertSame(0, PushSubscription::count());

        foreach ([
            'https://web.push.apple.com/QGxyz',
            'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'https://wns2-bl2p.notify.windows.com/w/?token=x',
        ] as $endpoint) {
            $this->assertTrue(PushNotifier::isPushEndpoint($endpoint), $endpoint);
        }
    }

    /** IDOR: another account cannot re-register my endpoint unless it holds the device's keys. */
    public function test_an_endpoint_cannot_be_taken_over_without_the_devices_keys(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($me)->postJson('/api/push/subscribe', self::SUB)->assertCreated();

        // Same endpoint, different keys → refused, row untouched.
        $this->actingAs($other)->postJson('/api/push/subscribe', ['endpoint' => self::SUB['endpoint'], 'keys' => ['p256dh' => 'OTHER', 'auth' => 'OTHER']])
            ->assertStatus(409);
        $this->assertSame($me->id, PushSubscription::first()->user_id);

        // Same endpoint AND the same keys = the same phone with a new sign-in → moves.
        $this->actingAs($other)->postJson('/api/push/subscribe', self::SUB)->assertCreated();
        $this->assertSame($other->id, PushSubscription::first()->user_id);
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_subscribe_is_refused_when_push_is_not_configured(): void
    {
        config(['push.vapid.private_key' => null]);

        $this->actingAs(User::factory()->create())->postJson('/api/push/subscribe', self::SUB)
            ->assertStatus(503);
    }

    public function test_unsubscribe_only_removes_my_own_row(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $hash = PushSubscription::hashEndpoint(self::SUB['endpoint']);
        PushSubscription::create(['user_id' => $other->id, 'endpoint_hash' => $hash, 'endpoint' => self::SUB['endpoint'], 'p256dh' => 'k', 'auth' => 'a']);

        $this->actingAs($me)->deleteJson('/api/push/subscribe', ['endpoint' => self::SUB['endpoint']])->assertNoContent();

        $this->assertDatabaseHas('push_subscriptions', ['endpoint_hash' => $hash]);
    }

    public function test_fan_out_respects_each_devices_threshold(): void
    {
        $u = User::factory()->create();
        PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => 'crit', 'endpoint' => 'https://p/crit', 'p256dh' => 'k', 'auth' => 'a', 'min_severity' => 'critical']);
        PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => 'warn', 'endpoint' => 'https://p/warn', 'p256dh' => 'k', 'auth' => 'a', 'min_severity' => 'warning']);

        $sentTo = [];
        PushNotifier::fakeSender(function (PushSubscription $s, string $payload) use (&$sentTo) {
            $sentTo[] = $s->endpoint_hash;
            $this->assertSame('Site 12 down', json_decode($payload, true)['title']);

            return ['ok' => true, 'status' => 201];
        });

        $this->assertSame(1, PushNotifier::fanOut('open', 'warning', 'Site 12 down', 'ISP circuit lost', ['circuit_id' => 12]));
        $this->assertSame(['warn'], $sentTo);

        $sentTo = [];
        $this->assertSame(2, PushNotifier::fanOut('open', 'critical', 'Site 12 down', 'ISP circuit lost', []));
        $this->assertEqualsCanonicalizing(['crit', 'warn'], $sentTo);
    }

    public function test_a_gone_subscription_is_dropped_and_flaky_ones_are_counted(): void
    {
        $u = User::factory()->create();
        $gone = PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => 'gone', 'endpoint' => 'https://p/gone', 'p256dh' => 'k', 'auth' => 'a', 'min_severity' => 'info']);
        $flaky = PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => 'flaky', 'endpoint' => 'https://p/flaky', 'p256dh' => 'k', 'auth' => 'a', 'min_severity' => 'info']);

        PushNotifier::fakeSender(fn (PushSubscription $s) => $s->endpoint_hash === 'gone'
            ? ['ok' => false, 'status' => 410]
            : ['ok' => false, 'status' => 500]);

        PushNotifier::fanOut('open', 'info', 'x', 'y');

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $gone->id]);
        $this->assertSame(1, $flaky->fresh()->failures);
    }

    /** A deactivated account hears nothing, and revoking its sessions drops its phones. */
    public function test_inactive_users_are_skipped_and_revocation_drops_subscriptions(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        PushSubscription::create(['user_id' => $active->id, 'endpoint_hash' => 'a', 'endpoint' => 'https://fcm.googleapis.com/a', 'p256dh' => 'k', 'auth' => 'a', 'min_severity' => 'info']);
        PushSubscription::create(['user_id' => $inactive->id, 'endpoint_hash' => 'i', 'endpoint' => 'https://fcm.googleapis.com/i', 'p256dh' => 'k', 'auth' => 'a', 'min_severity' => 'info']);

        $sentTo = [];
        PushNotifier::fakeSender(function (PushSubscription $s) use (&$sentTo) {
            $sentTo[] = $s->endpoint_hash;

            return ['ok' => true, 'status' => 201];
        });
        PushNotifier::fanOut('open', 'critical', 'x', 'y');
        $this->assertSame(['a'], $sentTo);

        $active->revokeSessions();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint_hash' => 'a']);
    }

    public function test_test_route_sends_to_my_device_only(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me)->postJson('/api/push/subscribe', self::SUB)->assertCreated();

        $hit = 0;
        PushNotifier::fakeSender(function () use (&$hit) {
            $hit++;

            return ['ok' => true, 'status' => 201];
        });

        $this->actingAs($me)->postJson('/api/push/test', ['endpoint' => self::SUB['endpoint']])->assertOk()->assertJsonPath('sent', true);
        $this->actingAs(User::factory()->create())->postJson('/api/push/test', ['endpoint' => self::SUB['endpoint']])->assertNotFound();
        $this->assertSame(1, $hit);
    }
}
