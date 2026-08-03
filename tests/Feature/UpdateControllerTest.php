<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.token' => 'fake-token', 'services.github.repo' => 'securaitllc/SecuraSNMP']);
    }

    public function test_viewer_cannot_check_for_updates(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/updates/check')->assertForbidden();
    }

    private function currentVersion(): string
    {
        return trim(file_get_contents(base_path('VERSION')));
    }

    public function test_reports_update_available_when_a_newer_tag_exists(): void
    {
        $current = $this->currentVersion();
        Http::fake([
            'api.github.com/*' => Http::response([
                ['name' => 'v99.0.0'],
                ['name' => 'v'.$current],
            ]),
        ]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/updates/check');

        $response->assertOk();
        $response->assertJson([
            'current' => $current,
            'latest' => '99.0.0',
            'update_available' => true,
        ]);
    }

    public function test_reports_no_update_when_current_is_latest(): void
    {
        $current = $this->currentVersion();
        Http::fake([
            'api.github.com/*' => Http::response([
                ['name' => 'v'.$current],
            ]),
        ]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/updates/check');

        $response->assertOk();
        $response->assertJson([
            'current' => $current,
            'latest' => $current,
            'update_available' => false,
        ]);
    }

    public function test_degrades_gracefully_when_github_api_fails(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 500),
        ]);

        $admin = User::factory()->admin()->create();

        // A GitHub hiccup must not 500 the update check (it pollutes the UI).
        $this->actingAs($admin)->getJson('/api/updates/check')
            ->assertOk()
            ->assertJsonPath('update_available', false);
    }

    public function test_degrades_gracefully_when_github_not_configured(): void
    {
        config(['services.github.token' => null]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/updates/check')
            ->assertOk()
            ->assertJsonPath('configured', false);
    }

    public function test_response_never_contains_the_github_token(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([['name' => 'v0.1.0']]),
        ]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/updates/check');

        $this->assertStringNotContainsString('fake-token', $response->getContent());
    }
}
