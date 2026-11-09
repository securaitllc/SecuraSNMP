<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SuperAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_is_a_superset_and_gates_admins_out_of_super_admin_routes(): void
    {
        $mw = new EnsureUserHasRole;
        $next = fn ($r) => new Response('ok');

        // super_admin satisfies its own gate AND every lower one (it is a superset).
        $super = User::factory()->create(['role' => 'super_admin']);
        $reqS = Request::create('/x');
        $reqS->setUserResolver(fn () => $super);
        $this->assertSame('ok', $mw->handle($reqS, $next, 'super_admin')->getContent());
        $this->assertSame('ok', $mw->handle($reqS, $next, 'admin')->getContent());

        // A regular admin does NOT inherit super_admin — the OSINT gate rejects them.
        $admin = User::factory()->create(['role' => 'admin']);
        $reqA = Request::create('/x');
        $reqA->setUserResolver(fn () => $admin);
        $this->assertThrows(fn () => $mw->handle($reqA, $next, 'super_admin'), HttpException::class);

        $this->assertTrue($super->isSuperAdmin());
        $this->assertFalse($admin->isSuperAdmin());
    }
}
