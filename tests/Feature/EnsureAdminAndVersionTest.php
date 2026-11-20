<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EnsureAdminAndVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_admin_creates_an_admin_when_none_exist(): void
    {
        $this->assertDatabaseCount('users', 0);

        Artisan::call('app:ensure-admin');

        $this->assertDatabaseHas('users', ['email' => 'admin@securasnmp.local', 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_ensure_admin_is_idempotent(): void
    {
        User::factory()->create();

        Artisan::call('app:ensure-admin');

        $this->assertDatabaseCount('users', 1); // no extra admin created
    }

    public function test_ensure_admin_uses_admin_password_env_when_set(): void
    {
        putenv('ADMIN_PASSWORD=Sup3r-Set-Pw!');
        Artisan::call('app:ensure-admin');
        putenv('ADMIN_PASSWORD');

        $user = User::where('email', 'admin@securasnmp.local')->firstOrFail();
        $this->assertTrue(\Hash::check('Sup3r-Set-Pw!', $user->password));
    }

    public function test_ensure_admin_generates_a_password_when_none_set(): void
    {
        putenv('ADMIN_PASSWORD'); // unset
        Artisan::call('app:ensure-admin');

        $user = User::where('email', 'admin@securasnmp.local')->firstOrFail();
        // A random password was set (not the old known default).
        $this->assertFalse(\Hash::check('ChangeMe123!', $user->password));
    }

    public function test_version_endpoint_returns_the_version(): void
    {
        $viewer = User::factory()->create();
        $expected = trim(file_get_contents(base_path('VERSION')));

        $this->actingAs($viewer)->getJson('/api/version')
            ->assertOk()
            ->assertJsonPath('version', $expected);
    }
}
