<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_list_users(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        User::factory()->count(2)->create();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/users');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_only_admin_can_change_a_users_mfa_requirement(): void
    {
        $target = User::factory()->create(['mfa_required' => false]);
        $body = fn () => [
            'name' => $target->name, 'email' => $target->email,
            'role' => $target->role, 'is_active' => true, 'mfa_required' => true,
        ];

        // Analyst and viewer can't reach user management, so can't flip MFA.
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->putJson("/api/users/{$target->id}", $body())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->putJson("/api/users/{$target->id}", $body())->assertForbidden();
        $this->assertFalse($target->fresh()->mfa_required);

        // Admin can.
        $this->actingAs(User::factory()->admin()->create())
            ->putJson("/api/users/{$target->id}", $body())->assertOk();
        $this->assertTrue($target->fresh()->mfa_required);
    }

    public function test_admin_can_create_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'New Viewer',
            'email' => 'newviewer@example.com',
            'password' => 'StrongPass1234',
            'role' => 'viewer',
            'is_active' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'newviewer@example.com', 'role' => 'viewer']);
    }

    public function test_create_user_rejects_a_weak_password(): void
    {
        $admin = User::factory()->admin()->create();

        // 'password123' — 9 chars, no uppercase — fails min:12 + mixedCase + numbers.
        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Weak', 'email' => 'weak@example.com',
            'password' => 'password123', 'role' => 'viewer', 'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_create_user_requires_unique_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Dup',
            'email' => 'taken@example.com',
            'password' => 'StrongPass1234',
            'role' => 'viewer',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'viewer',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'admin',
            'is_active' => false,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_delete_other_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/users/{$target->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_can_reset_a_users_two_factor_keeping_the_requirement(): void
    {
        $admin = User::factory()->admin()->create();
        $enrolled = User::factory()->create([
            'mfa_required' => true,
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['x'],
        ]);

        $response = $this->actingAs($admin)->postJson("/api/users/{$enrolled->id}/reset-two-factor");

        $response->assertOk();
        $enrolled->refresh();
        $this->assertNull($enrolled->two_factor_confirmed_at);   // enrollment wiped
        $this->assertNull($enrolled->two_factor_secret);
        $this->assertTrue((bool) $enrolled->mfa_required);        // requirement kept → re-enrols next login
    }

    public function test_viewer_cannot_reset_two_factor(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($viewer)->postJson("/api/users/{$target->id}/reset-two-factor")->assertForbidden();
    }

    /** A session the target opened before an admin acted on the account. */
    private function targetSession(User $target): array
    {
        return ['auth_session' => ['user' => $target->id, 'login_at' => now()->timestamp, 'epoch' => (int) $target->session_epoch]];
    }

    private function body(User $target, array $extra = []): array
    {
        return array_merge([
            'name' => $target->name, 'email' => $target->email,
            'role' => $target->role, 'is_active' => true,
        ], $extra);
    }

    /** AUTH-01: an admin password reset rotates the remember token and ends the target's sessions. */
    public function test_admin_password_reset_rotates_remember_token_and_ends_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['remember_token' => 'stolen-cookie-token']);
        $before = $this->targetSession($target);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->body($target, ['password' => 'ResetPassw0rd99']))
            ->assertOk();

        $this->assertNotSame('stolen-cookie-token', $target->fresh()->remember_token);
        $this->actingAs($target->fresh())->withSession($before)->getJson('/api/user')->assertStatus(401);
    }

    /** AUTH-01: an admin 2FA reset also rotates the remember token and ends the target's sessions. */
    public function test_admin_two_factor_reset_rotates_remember_token_and_ends_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['remember_token' => 'stolen-cookie-token', 'two_factor_confirmed_at' => now()]);
        $before = $this->targetSession($target);

        $this->actingAs($admin)->postJson("/api/users/{$target->id}/reset-two-factor")->assertOk();

        $this->assertNotSame('stolen-cookie-token', $target->fresh()->remember_token);
        $this->actingAs($target->fresh())->withSession($before)->getJson('/api/user')->assertStatus(401);
    }

    /** AUTH-02: deactivate then reactivate does not resurrect the old session or cookie. */
    public function test_deactivation_ends_sessions_for_good(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['remember_token' => 'stolen-cookie-token']);
        $before = $this->targetSession($target);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->body($target, ['is_active' => false]))->assertOk();
        $this->assertNotSame('stolen-cookie-token', $target->fresh()->remember_token);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->body($target, ['is_active' => true]))->assertOk();
        $this->actingAs($target->fresh())->withSession($before)->getJson('/api/user')->assertStatus(401);
    }

    /** AUTH-02 control: an ordinary edit (name) leaves the target signed in. */
    public function test_an_ordinary_edit_keeps_the_target_signed_in(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['remember_token' => 'kept-token']);
        $before = $this->targetSession($target);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->body($target, ['name' => 'Renamed']))->assertOk();

        $this->assertSame('kept-token', $target->fresh()->remember_token);
        $this->actingAs($target->fresh())->withSession($before)->getJson('/api/user')->assertOk();
    }

    /** ACL-01: an admin cannot set a peer admin's password. */
    public function test_admin_cannot_reset_a_peer_admins_password(): void
    {
        $admin = User::factory()->admin()->create();
        $peer = User::factory()->admin()->create(['password' => bcrypt('PeerPassw0rd99')]);

        $this->actingAs($admin)->putJson("/api/users/{$peer->id}", $this->body($peer, ['password' => 'Takeover12345X']))
            ->assertForbidden();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('PeerPassw0rd99', $peer->fresh()->password));
    }

    /** ACL-01: an admin cannot strip a peer admin's MFA, by toggle or by reset. */
    public function test_admin_cannot_strip_a_peer_admins_mfa(): void
    {
        $admin = User::factory()->admin()->create();
        $peer = User::factory()->admin()->create([
            'mfa_required' => true, 'two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)->putJson("/api/users/{$peer->id}", $this->body($peer, ['mfa_required' => false]))
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/users/{$peer->id}/reset-two-factor")->assertForbidden();

        $peer->refresh();
        $this->assertTrue($peer->mfa_required);
        $this->assertTrue($peer->hasTwoFactorEnabled());
    }

    /** ACL-01: name/role/active edits of a peer stay allowed; resending the unchanged MFA flag is not a change. */
    public function test_admin_can_still_edit_a_peer_admins_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $peer = User::factory()->admin()->create(['mfa_required' => true]);

        $this->actingAs($admin)->putJson("/api/users/{$peer->id}", $this->body($peer, ['name' => 'Peer Renamed', 'mfa_required' => true]))
            ->assertOk();

        $this->assertSame('Peer Renamed', $peer->fresh()->name);
    }

    /** ACL-01: strict outrank, so admin to viewer works; super_admin to super_admin works (no higher rank exists). */
    public function test_password_reset_allowed_down_the_ladder_and_between_super_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->create();
        $this->actingAs($admin)->putJson("/api/users/{$viewer->id}", $this->body($viewer, ['password' => 'ResetPassw0rd99']))
            ->assertOk();

        $super = User::factory()->create(['role' => 'super_admin']);
        $otherSuper = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->putJson("/api/users/{$otherSuper->id}", $this->body($otherSuper, ['password' => 'ResetPassw0rd99']))
            ->assertOk();
    }

    /** ACL-03: admin-only fields follow the rank ladder, so super_admin sees what admin sees. */
    public function test_super_admin_counts_as_admin(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->assertTrue($super->isAdmin());
        $this->assertFalse(User::factory()->create(['role' => 'analyst'])->isAdmin());

        $device = \App\Models\Device::factory()->create([
            'snmp_version' => 'v3', 'snmp_v3_username' => 'noc-v3', 'ssh_username' => 'noc-ssh',
        ]);

        $this->actingAs($super)->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.snmp_v3_username', 'noc-v3')
            ->assertJsonPath('data.ssh_username', 'noc-ssh');
    }
}
