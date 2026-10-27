<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.email', 'admin@example.com');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertOk();

        $this->assertGuest();
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_me_endpoint_returns_current_user(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('email', $user->email);
        $response->assertJsonPath('role', 'admin');
    }

    public function test_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('OldPassw0rd!')]);

        $this->actingAs($user)->postJson('/api/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPass99',
            'password_confirmation' => 'BrandNewPass99',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('BrandNewPass99', $user->fresh()->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('OldPassw0rd!')]);

        $this->actingAs($user)->postJson('/api/password', [
            'current_password' => 'wrong',
            'password' => 'BrandNewPass99',
            'password_confirmation' => 'BrandNewPass99',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('OldPassw0rd!', $user->fresh()->password));
    }

    /** L-03: an unknown email returns the identical response as a wrong password. */
    public function test_unknown_email_is_indistinguishable_from_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'real@example.com', 'password' => bcrypt('secret123')]);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'real@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertGuest();
    }

    /** L-04: a malformed email keys only the per-IP bucket, never a per-account one. */
    public function test_malformed_email_attempts_are_not_account_limited(): void
    {
        // The per-account limit is 5/min; the per-IP limit is 8/min. If a malformed
        // email still minted an account bucket, the 6th attempt would 429. With the
        // fix it rides the per-IP limit alone, so it is still the validation 422.
        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'x']);
        }

        $this->assertSame(422, $last->status());
    }

    /** L-04: a real account IS locked after 5 wrong attempts (anti-stuffing intact). */
    public function test_valid_email_is_account_limited_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => bcrypt('secret123')]);

        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = $this->postJson('/api/login', ['email' => 'victim@example.com', 'password' => 'wrong']);
        }

        $this->assertSame(429, $last->status());
    }

    /** L-05 / AUTH-06: a password change evicts other devices, keeps this one, rotates the token. */
    public function test_password_change_evicts_other_sessions_and_rotates_remember_token(): void
    {
        $user = User::factory()->create([
            'password' => \Illuminate\Support\Facades\Hash::make('OldPassw0rd!'),
            'remember_token' => 'old-token-123',
        ]);
        $oldHash = \Illuminate\Support\Facades\Auth::guard('web')->hashPasswordForCookie($user->password);

        $this->actingAs($user)->postJson('/api/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPass99',
            'password_confirmation' => 'BrandNewPass99',
        ])->assertOk();

        $this->assertNotSame('old-token-123', $user->fresh()->remember_token);

        // This session carries the new password fingerprint and stays signed in.
        $this->assertSame(
            \Illuminate\Support\Facades\Auth::guard('web')->hashPasswordForCookie($user->fresh()->password),
            session('password_hash_web'),
        );
        $this->actingAs($user->fresh())->getJson('/api/user')->assertOk();

        // Another device still holds the old fingerprint: AuthenticateSession ends it,
        // whatever the session driver (prod runs redis, where a sessions-table purge
        // did nothing).
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->getJson('/api/user')->assertStatus(401);
    }

    /** AUTH-06: the login itself pins the password fingerprint, so a session idle since login is still evicted. */
    public function test_login_stores_the_password_fingerprint_in_the_session(): void
    {
        $user = User::factory()->create(['email' => 'pin@example.com', 'password' => bcrypt('PinPassw0rd99')]);

        $this->postJson('/api/login', ['email' => 'pin@example.com', 'password' => 'PinPassw0rd99'])->assertOk();

        $this->assertSame(
            \Illuminate\Support\Facades\Auth::guard('web')->hashPasswordForCookie($user->password),
            session('password_hash_web'),
        );
    }

    /** AUTH-04: an accented variant of a locked address shares the same account bucket. */
    public function test_accented_email_variant_shares_the_account_lock(): void
    {
        User::factory()->create(['email' => 'pt-auth-pw@example.com', 'password' => bcrypt('secret123')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'pt-auth-pw@example.com', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['email' => 'PT-áuth-pw@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
