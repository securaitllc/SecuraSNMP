<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NVD expresses affected ranges with explicit inclusivity
        // (versionStartIncluding/Excluding, versionEndIncluding/Excluding). Model it
        // so ingested ranges are exact. Defaults match the starter catalog's
        // convention: lower bound inclusive, upper bound (the fix) exclusive.
        Schema::table('cve_affects', function (Blueprint $table) {
            $table->boolean('introduced_inclusive')->default(true)->after('version_introduced');
            $table->boolean('fixed_inclusive')->default(false)->after('version_fixed');
        });
    }

    public function down(): void
    {
        Schema::table('cve_affects', function (Blueprint $table) {
            $table->dropColumn(['introduced_inclusive', 'fixed_inclusive']);
        });
    }
};
