<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCensus(): void
    {
        Http::fake([
            '*geocoding.geo.census.gov*' => Http::response([
                'result' => ['addressMatches' => [[
                    'coordinates' => ['x' => -81.3789, 'y' => 28.5384],
                ]]],
            ]),
        ]);
    }

    public function test_geocode_endpoint_resolves_an_address(): void
    {
        $this->fakeCensus();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/sites/geocode', ['address' => '3210 Clay Ave, Orlando, FL']);

        $response->assertOk();
        $this->assertEqualsWithDelta(28.5384, $response->json('latitude'), 0.0001);
        $this->assertEqualsWithDelta(-81.3789, $response->json('longitude'), 0.0001);
    }

    public function test_creating_a_site_auto_fills_coordinates_from_the_address(): void
    {
        $this->fakeCensus();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/sites', [
            'name' => 'Orlando HQ',
            'address' => '3210 Clay Ave, Orlando, FL',
        ]);

        $response->assertCreated();
        $this->assertEqualsWithDelta(28.5384, $response->json('latitude'), 0.0001);
        $this->assertEqualsWithDelta(-81.3789, $response->json('longitude'), 0.0001);
    }

    public function test_supplied_coordinates_are_not_overwritten(): void
    {
        $this->fakeCensus();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/sites', [
            'name' => 'Manual',
            'address' => 'somewhere',
            'latitude' => 10.0,
            'longitude' => 20.0,
        ]);

        $response->assertCreated();
        $this->assertSame(10.0, (float) $response->json('latitude'));
        $this->assertSame(20.0, (float) $response->json('longitude'));
    }

    public function test_no_match_returns_a_helpful_error(): void
    {
        // Both geocoders miss (Census empty, Nominatim empty) → a helpful 422.
        Http::fake([
            '*geocoding.geo.census.gov*' => Http::response(['result' => ['addressMatches' => []]]),
            '*nominatim.openstreetmap.org*' => Http::response([]),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/sites/geocode', ['address' => 'nowhere'])
            ->assertStatus(422);
    }
}
