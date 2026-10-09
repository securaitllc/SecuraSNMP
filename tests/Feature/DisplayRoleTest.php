<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_account_can_read_the_wallboard_feed_and_itself(): void
    {
        $display = User::factory()->create(['role' => 'display']);

        $this->actingAs($display)->getJson('/api/dashboard')->assertOk();
        $this->actingAs($display)->getJson('/api/user')->assertOk();
    }

    public function test_display_account_is_fenced_out_of_everything_else(): void
    {
        $display = User::factory()->create(['role' => 'display']);

        $this->actingAs($display)->getJson('/api/devices')->assertForbidden();
        $this->actingAs($display)->getJson('/api/sites')->assertForbidden();
        $this->actingAs($display)->getJson('/api/alarms/log')->assertForbidden();
    }

    public function test_display_account_never_requires_mfa_even_when_flagged(): void
    {
        $display = User::factory()->create(['role' => 'display', 'mfa_required' => true]);

        $this->assertFalse($display->mustUseTwoFactor());
    }
}
