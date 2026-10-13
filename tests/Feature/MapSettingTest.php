<?php

namespace Tests\Feature;

use App\Models\MapSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CARTO stamps "API KEY REQUIRED" across basemap tiles fetched without a key, so
 * the dashboard map needs one. The key is a secret (encrypted at rest, never
 * returned), and rotating it must not leave weeks of watermarked tiles cached.
 */
class MapSettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_key_is_never_returned_only_a_masked_hint(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/map-settings', ['api_key' => 'super-secret-carto-key-abcd'])
            ->assertOk()
            ->assertJson(['has_key' => true, 'masked_key' => '••••••••abcd'])
            ->assertJsonMissing(['api_key' => 'super-secret-carto-key-abcd']);

        $this->assertSame('super-secret-carto-key-abcd', MapSetting::tileKey());
    }

    public function test_the_key_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->admin())->putJson('/api/map-settings', ['api_key' => 'plaintext-should-not-persist'])->assertOk();

        $raw = \DB::table('map_settings')->where('id', 1)->value('api_key');
        $this->assertNotSame('plaintext-should-not-persist', $raw, 'the key must not sit in the DB in plain text');
        $this->assertNotNull($raw);
    }

    public function test_a_blank_field_keeps_the_existing_key(): void
    {
        // A blank input means "leave it alone" — otherwise saving any other setting
        // would silently wipe the key and bring the watermark back.
        $admin = $this->admin();
        $this->actingAs($admin)->putJson('/api/map-settings', ['api_key' => 'keep-me-1234'])->assertOk();
        $this->actingAs($admin)->putJson('/api/map-settings', ['api_key' => ''])->assertOk();

        $this->assertSame('keep-me-1234', MapSetting::tileKey());
    }

    public function test_clearing_the_key_is_explicit(): void
    {
        // Blank keeps, so removing a key needs its own signal.
        $admin = $this->admin();
        $this->actingAs($admin)->putJson('/api/map-settings', ['api_key' => 'remove-me-5678'])->assertOk();

        $this->actingAs($admin)->putJson('/api/map-settings', ['clear_key' => true])
            ->assertOk()
            ->assertJson(['has_key' => false]);

        $this->assertNull(MapSetting::tileKey());
    }

    public function test_only_an_admin_can_read_or_change_it(): void
    {
        foreach (['viewer', 'analyst'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson('/api/map-settings')->assertForbidden();
            $this->actingAs($user)->putJson('/api/map-settings', ['api_key' => 'nope'])->assertForbidden();
        }
    }

    public function test_rotating_the_key_changes_the_tile_cache_namespace(): void
    {
        // The 30-day tile cache is namespaced by a fingerprint of the key. Without
        // this, a new key would keep serving the old key's watermarked tiles for weeks.
        $none = MapSetting::cacheNamespace();
        $this->assertSame('nokey', $none);

        MapSetting::current()->update(['api_key' => 'first-key']);
        $first = MapSetting::cacheNamespace();

        MapSetting::current()->update(['api_key' => 'second-key']);
        $second = MapSetting::cacheNamespace();

        $this->assertNotSame($none, $first);
        $this->assertNotSame($first, $second, 'a rotated key must read from a fresh cache namespace');
    }

    public function test_the_proxy_appends_the_key_and_caches_per_key(): void
    {
        Cache::store('file')->flush();
        Http::fake(['*' => Http::response('PNGDATA', 200)]);
        MapSetting::current()->update(['api_key' => 'tile-key-9999']);

        $this->get('/map-tiles/dark/6/17/25')->assertOk()->assertHeader('Content-Type', 'image/png');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'rastertiles/dark_all/6/17/25.png')
            // CARTO's parameter is `key`, not `api_key`.
            && str_contains($req->url(), 'key=tile-key-9999'));
    }

    public function test_no_key_still_serves_a_map_rather_than_failing(): void
    {
        // Watermarked beats blank: an unconfigured server must still draw the map.
        Cache::store('file')->flush();
        Http::fake(['*' => Http::response('PNGDATA', 200)]);

        $this->get('/map-tiles/light/6/17/25')->assertOk();

        Http::assertSent(fn ($req) => ! str_contains($req->url(), 'key='));
    }

    public function test_the_test_endpoint_reports_a_key_that_is_not_honoured(): void
    {
        // CARTO answers 200 with a WATERMARKED tile for a bad key, so an identical
        // response to an unkeyed fetch is the only way to detect it.
        MapSetting::current()->update(['api_key' => 'bogus']);
        Http::fake(['*' => Http::response('IDENTICAL-WATERMARKED-TILE', 200)]);

        $this->actingAs($this->admin())->postJson('/api/map-settings/test')
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_the_test_endpoint_confirms_a_working_key(): void
    {
        MapSetting::current()->update(['api_key' => 'good-key']);
        Http::fake(fn ($req) => str_contains($req->url(), 'key=')
            ? Http::response('CLEAN-TILE', 200)
            : Http::response('WATERMARKED-TILE', 200));

        $this->actingAs($this->admin())->postJson('/api/map-settings/test')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
