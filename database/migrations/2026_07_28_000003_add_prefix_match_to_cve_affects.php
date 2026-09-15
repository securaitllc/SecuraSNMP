<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NVD enumerates JunOS affected releases as exact CPE versions (base + update
        // segment, e.g. 20.4 + r3-s2). These rows match by canonical JunOS equality
        // (major, minor, R/X/F, S — build/spin ignored) so a device's trailing spin
        // (20.4R3-S2.6) still matches its release while the patched 20.4R3-S9 does not.
        Schema::table('cve_affects', function (Blueprint $table) {
            $table->boolean('exact_match')->default(false)->after('fixed_inclusive');
        });
    }

    public function down(): void
    {
        Schema::table('cve_affects', function (Blueprint $table) {
            $table->dropColumn('prefix_match');
        });
    }
};
