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
}
