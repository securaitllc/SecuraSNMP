<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton settings row for the dashboard basemap.
 *
 * CARTO began watermarking keyless basemap tiles ("API KEY REQUIRED" stamped
 * across the map), so the tile proxy needs a key to fetch clean tiles. The key is
 * encrypted at rest like every other secret here — same store as SNMP/SSH creds.
 *
 * provider is a plain string, NOT a DB enum: SQLite (dev/tests) ignores enum
 * constraints while MySQL (prod) rejects unknown values, so enums pass locally and
 * 500 in production. Validated with `in:` at the request layer instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('carto');
            $table->text('api_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_settings');
    }
};
