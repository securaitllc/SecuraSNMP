<?php

use App\Http\Controllers\Api\MapTileController;
use Illuminate\Support\Facades\Route;

// Local proxy + cache for the dashboard map's basemap tiles. Public (tiles are
// public map data, requested straight by Leaflet) and registered before the SPA
// catch-all so it isn't swallowed by it.
// Must be declared BEFORE the {style}/{z}/{x}/{y} tile route, or "version" would
// be matched as a style and 404.
// Throttled per client: the proxy is unauthenticated, and every uncached tile is
// an outbound fetch on the paid CARTO key plus a 30-day disk-cache write.
Route::get('/map-tiles/version', [MapTileController::class, 'version'])
    ->middleware('throttle:120,1');

Route::get('/map-tiles/{style}/{z}/{x}/{y}', [MapTileController::class, 'show'])
    ->where(['style' => 'dark|light', 'z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+'])
    ->middleware('throttle:120,1');

// SPA shell. Everything under /api is the JSON API: an unknown path there must
// be a JSON 404, not the shell.
Route::get('{any?}', function() {
    return view('application');
})->where('any', '^(?!api/).*$');