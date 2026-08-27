<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MapSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local proxy + on-disk cache for the dashboard map's basemap tiles.
 *
 * The Leaflet map pulls tiles from the CARTO CDN. The Nodus server reaches CARTO
 * in ~0.07s, but the operators' browsers going out to the internet for every tile
 * is what makes the map feel slow. Serving tiles from here — fetched once from
 * CARTO, then cached — means each browser fetches over the fast LAN and repeat
 * loads (every NOC screen) hit the cache.
 *
 * CARTO now stamps "API KEY REQUIRED" across tiles fetched without a key, so the
 * key from MapSetting is appended when configured. Without one the map still
 * draws — just watermarked — and a WRONG key behaves the same way rather than
 * blanking the map, because CARTO answers 200 with the watermarked tile either way.
 */
class MapTileController extends Controller
{
    private const STYLES = ['dark' => 'dark_all', 'light' => 'light_all'];

    public function show(string $style, int $z, int $x, int $y): Response
    {
        $cartoStyle = self::STYLES[$style] ?? null;
        if ($cartoStyle === null || $z > 19 || $x < 0 || $y < 0) {
            abort(404);
        }

        $apiKey = MapSetting::tileKey();
        $url = "https://basemaps.cartocdn.com/rastertiles/{$cartoStyle}/{$z}/{$x}/{$y}.png";
        if ($apiKey !== null) {
            // CARTO's parameter is `key`, not `api_key`.
            $url .= '?key='.urlencode($apiKey);
        }

        // The cache is namespaced by a fingerprint of the active key: adding or
        // rotating a key must not keep serving the previous key's tiles (30-day TTL
        // would otherwise leave watermarked tiles on screen for weeks). The `v2`
        // prefix retires everything cached under the old keyless URL scheme.
        $key = 'maptile:v2:'.MapSetting::cacheNamespace().":{$style}:{$z}:{$x}:{$y}";
        $store = Cache::store('file');   // binary tiles belong on disk, not the DB cache

        $png = $store->get($key);
        if ($png === null) {
            try {
                $res = Http::timeout(8)->get($url);
                if ($res->successful()) {
                    $png = $res->body();
                    $store->put($key, $png, now()->addDays(30)); // never cache a failed fetch
                }
            } catch (\Throwable) {
                // fall through to the CDN redirect below
            }
        }

        if ($png === null) {
            return redirect()->away($url); // CDN fallback — map still draws
        }

        return response($png, 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=2592000, immutable');
    }
}
