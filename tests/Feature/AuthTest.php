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
}
