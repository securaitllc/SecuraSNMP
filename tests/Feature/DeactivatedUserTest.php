<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivatedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_with_an_existing_session_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Simulate an already-authenticated session, then deactivate the account
        // out from under it (e.g. an admin deactivating them mid-session).
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_active_user_is_unaffected(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
    }
}
