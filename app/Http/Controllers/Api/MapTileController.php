<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
 * loads (every NOC screen) hit the cache. Falls back to the CDN if a fetch fails,
 * so the map never blanks.
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

        $url = "https://a.basemaps.cartocdn.com/{$cartoStyle}/{$z}/{$x}/{$y}.png";
        $key = "maptile:{$style}:{$z}:{$x}:{$y}";
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
