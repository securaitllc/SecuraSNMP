<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Massey runs a hub-and-spoke WAN: a couple of HUB sites, and every branch
    // homes to one of them over SD-WAN. Modeling that lets the topology group
    // 130+ branches under their hub instead of one flat wall of tiles.
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('site_type')->default('branch')->after('name'); // 'hub' | 'branch'
            $table->foreignId('hub_site_id')->nullable()->after('site_type')->constrained('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hub_site_id');
            $table->dropColumn('site_type');
        });
    }
};
