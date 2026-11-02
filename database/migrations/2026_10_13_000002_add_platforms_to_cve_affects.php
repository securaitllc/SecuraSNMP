<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cve_affects', function (Blueprint $table) {
            // Hardware platform families this range is restricted to, as a JSON array
            // of lowercase tokens (["srx","mx"]). NULL means the CVE is platform
            // independent and applies to any device running an affected version.
            //
            // NVD expresses a platform restriction as a configuration with
            // "operator": "AND" — one node carrying the vulnerable OS CPE and a sibling
            // node carrying the hardware CPEs it runs on. The parser used to flatten
            // configurations/nodes/cpeMatch and throw that structure away, so an
            // SRX-firewall CVE was applied to every EX access switch in the estate.
            // 375 of 792 JunOS CVEs (47%) carry such a gate.
            $table->json('platforms')->nullable()->after('product');
        });
    }

    public function down(): void
    {
        Schema::table('cve_affects', function (Blueprint $table) {
            $table->dropColumn('platforms');
        });
    }
};
