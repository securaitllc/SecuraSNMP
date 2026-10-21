<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The user editor must enforce the same rank ladder the route middleware does.
 *
 * /api/users is gated at role:admin, which reads as "admins manage users" — but
 * super_admin is a rank ABOVE admin and gates the OSINT tool. Without these fences
 * an admin could mint themselves a super_admin, or delete the one that exists.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'Str0ngPassword!23',
            'role' => 'viewer',
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_cannot_create_a_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/users', $this->payload(['role' => 'super_admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public function test_admin_cannot_promote_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", $this->payload([
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'role' => 'super_admin',
            ]))
            ->assertStatus(422);

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_admin_cannot_edit_delete_or_unenrol_a_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $super = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->putJson("/api/users/{$super->id}", $this->payload([
                'name' => $super->name,
                'email' => $super->email,
                'password' => '',
                'role' => 'admin',
            ]))
            ->assertStatus(403);

        $this->actingAs($admin)->deleteJson("/api/users/{$super->id}")->assertStatus(403);
        $this->actingAs($admin)->postJson("/api/users/{$super->id}/reset-two-factor")->assertStatus(403);

        $this->assertSame('super_admin', $super->fresh()->role);
        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_admin_can_still_manage_accounts_at_or_below_their_rank(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/users', $this->payload(['role' => 'admin']))
            ->assertStatus(201);

        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($admin)->deleteJson("/api/users/{$analyst->id}")->assertStatus(204);
    }

    public function test_super_admin_can_create_a_super_admin(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($super)
            ->postJson('/api/users', $this->payload(['role' => 'super_admin']))
            ->assertStatus(201);
    }
}
