<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiNotFoundTest extends TestCase
{
    use RefreshDatabase;

    /** MISC-09: an unknown /api/* path is a JSON 404, not the SPA shell with a 200. */
    public function test_misc09_unknown_api_path_is_a_json_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');

        $this->getJson('/api/v1/user')->assertNotFound();
    }

    /** MISC-09: the SPA catch-all still serves client-side routes. */
    public function test_misc09_spa_routes_still_render_the_shell(): void
    {
        $this->withoutVite();

        $this->get('/circuits')->assertOk()->assertSee('id="app"', false);
        $this->get('/apiary')->assertOk(); // only the api/ prefix is excluded
    }

    /** SSRF-07: the public map-tile proxy is rate limited per client. */
    public function test_ssrf07_map_tile_routes_are_throttled(): void
    {
        foreach (['/map-tiles/version', '/map-tiles/dark/3/2/1'] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri));
            $this->assertContains('throttle:120,1', $route->gatherMiddleware(), "$uri is not throttled");
        }
    }
}
