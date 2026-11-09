<?php

namespace Tests\Feature;

use App\Models\MapSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapTileProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The cache key now carries a version + a fingerprint of the active API
        // key, so clear the whole store rather than one hand-built key.
        Cache::store('file')->flush();
        Cache::forget('mapsetting:tilekey');
    }

    public function test_it_proxies_and_caches_a_tile(): void
    {
        Http::fake(['*basemaps.cartocdn.com*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);

        $this->get('/map-tiles/dark/6/17/26')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // Second request must serve from cache — no second upstream fetch.
        $this->get('/map-tiles/dark/6/17/26')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_the_cdn_when_the_fetch_fails(): void
    {
        Http::fake(['*basemaps.cartocdn.com*' => Http::response('', 502)]);

        $this->get('/map-tiles/dark/6/17/26')
            ->assertRedirect('https://basemaps.cartocdn.com/rastertiles/dark_all/6/17/26.png');
    }

    public function test_it_rejects_an_unknown_style(): void
    {
        Http::fake();
        // Bad style fails the route constraint → SPA catch-all, never the tile proxy.
        $this->get('/map-tiles/evil/6/17/26');
        Http::assertNothingSent();
    }

    public function test_the_cdn_fallback_never_leaks_the_api_key(): void
    {
        // The proxy exists to keep the CARTO key server-side. On a fetch failure the
        // fallback redirect must go to the KEYLESS URL — redirecting to the keyed URL
        // would emit the secret in a client-visible 302 Location header.
        MapSetting::query()->updateOrCreate(['id' => 1], ['provider' => 'carto', 'api_key' => 'SECRET-TILE-KEY-123']);
        Cache::forget('mapsetting:tilekey');
        Http::fake(['*basemaps.cartocdn.com*' => Http::response('', 502)]);

        $location = (string) $this->get('/map-tiles/dark/6/17/26')
            ->assertRedirect('https://basemaps.cartocdn.com/rastertiles/dark_all/6/17/26.png')
            ->headers->get('Location');

        $this->assertStringNotContainsString('key=', $location, 'the fallback must not carry the API key');
        $this->assertStringNotContainsString('SECRET-TILE-KEY-123', $location);
    }
}
