<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MapTileController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSRF-07 / MISC-03: the public tile proxy only capped z, so x/y could name tiles
 * that do not exist (2^38 distinct keys at z19), each one an outbound CARTO fetch
 * on the org's key and a 30-day file on disk.
 *
 * SC-01: the admin "clear tile cache" button flushed the whole file cache store,
 * not just tiles.
 */
class MapTileBoundsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MapTileController::tileStore()->flush();
        Cache::forget('mapsetting:tilekey');
    }

    public static function outOfRange(): array
    {
        return [
            'x past the edge' => ['/map-tiles/dark/6/64/10'],
            'y past the edge' => ['/map-tiles/dark/6/10/64'],
            'z0 has one tile' => ['/map-tiles/dark/0/1/0'],
            'z above 19' => ['/map-tiles/dark/20/0/0'],
        ];
    }

    /**
     * SSRF-07: a tile coordinate outside the z grid is refused before any fetch or write.
     *
     * @dataProvider outOfRange
     */
    public function test_an_impossible_tile_is_refused_without_fetching(string $path): void
    {
        Http::fake();

        $this->get($path)->assertNotFound();

        Http::assertNothingSent();
    }

    /** SSRF-07: the last valid tile on each axis still proxies. */
    public function test_the_grid_edges_still_proxy(): void
    {
        Http::fake(['*basemaps.cartocdn.com*' => Http::response('PNG', 200, ['Content-Type' => 'image/png'])]);

        $this->get('/map-tiles/dark/0/0/0')->assertOk();
        $this->get('/map-tiles/dark/6/63/63')->assertOk();
        $this->get('/map-tiles/light/19/524287/524287')->assertOk();
    }

    /** SC-01: clearing tiles leaves every other file-cache entry alone. */
    public function test_flushing_tiles_does_not_wipe_the_file_cache(): void
    {
        Http::fake(['*basemaps.cartocdn.com*' => Http::response('PNG', 200, ['Content-Type' => 'image/png'])]);
        Cache::store('file')->put('unrelated:entry', 'keep-me', 600);

        $this->get('/map-tiles/dark/6/17/26')->assertOk();
        Http::assertSentCount(1);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/map-settings/flush')
            ->assertOk();

        $this->assertSame('keep-me', Cache::store('file')->get('unrelated:entry'));

        // The tile itself is gone: the next request fetches upstream again.
        $this->get('/map-tiles/dark/6/17/26')->assertOk();
        Http::assertSentCount(2);

        Cache::store('file')->forget('unrelated:entry');
    }
}
