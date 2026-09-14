<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Sessions expire after N hours" was never true for the people using this app.
 *
 * A Laravel session lifetime is INACTIVITY, not age — every request slides it
 * forward, and the NOC polls every 30 seconds, so an open tab renewed itself
 * indefinitely. Remember-me covered whatever was left: when a session did lapse, the
 * cookie re-authenticated on the next request with no password and no authenticator
 * prompt.
 *
 * So the deadline cannot live in the session, which a remember-me revival replaces.
 * It lives on the user, written only by a real credential login.
 */
class MaxSessionAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.max_session_hours' => 8]);
    }

    public function test_a_session_older_than_the_limit_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true, 'last_login_at' => now()->subHours(9)]);

        $this->actingAs($user)->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('reason', 'max_session_age');
    }

    public function test_a_session_inside_the_limit_is_fine(): void
    {
        $user = User::factory()->create(['is_active' => true, 'last_login_at' => now()->subHours(7)]);

        $this->actingAs($user)->getJson('/api/user')->assertOk();
    }

    public function test_polling_does_not_extend_the_limit(): void
    {
        // The whole point: activity used to renew the session forever. Age is
        // measured from the login, so requests in between change nothing.
        $user = User::factory()->create(['is_active' => true, 'last_login_at' => now()->subHours(7)]);

        $this->actingAs($user)->getJson('/api/user')->assertOk();
        $this->travel(90)->minutes();
        $this->actingAs($user)->getJson('/api/user')->assertStatus(401);
    }

    public function test_an_unknown_login_time_is_not_treated_as_fresh(): void
    {
        // A session revived from a remember-me cookie never passes through the login
        // controller, so it has no recorded login. An age nothing established must
        // not be read as a new one.
        $user = User::factory()->create(['is_active' => true, 'last_login_at' => null]);

        $this->actingAs($user)->getJson('/api/user')->assertStatus(401);
    }

    public function test_a_wall_display_is_exempt(): void
    {
        // A display account signs into a mounted TV once and is meant to stay up.
        // Nobody is there at 3am to sign it back in.
        $user = User::factory()->create([
            'is_active' => true, 'role' => 'display', 'last_login_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)->getJson('/api/wallboard')->assertStatus(200);
    }

    public function test_logging_in_restarts_the_clock(): void
    {
        $user = User::factory()->create([
            'email' => 'noc@securasnmp.local',
            'password' => bcrypt('ChangeMe123!'),
            'is_active' => true,
            'last_login_at' => now()->subHours(20),
        ]);

        $this->postJson('/api/login', ['email' => 'noc@securasnmp.local', 'password' => 'ChangeMe123!'])
            ->assertOk();

        $this->assertTrue($user->fresh()->last_login_at->greaterThan(now()->subMinute()));
    }

    public function test_the_limit_can_be_turned_off(): void
    {
        config(['auth.max_session_hours' => 0]);
        $user = User::factory()->create(['is_active' => true, 'last_login_at' => now()->subDays(5)]);

        $this->actingAs($user)->getJson('/api/user')->assertOk();
    }
}
