<?php

use App\Http\Controllers\Api\MapTileController;
use Illuminate\Support\Facades\Route;

// Local proxy + cache for the dashboard map's basemap tiles. Public (tiles are
// public map data, requested straight by Leaflet) and registered before the SPA
// catch-all so it isn't swallowed by it.
Route::get('/map-tiles/{style}/{z}/{x}/{y}', [MapTileController::class, 'show'])
    ->where(['style' => 'dark|light', 'z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+']);

Route::get('{any?}', function() {
    return view('application');
})->where('any', '.*');