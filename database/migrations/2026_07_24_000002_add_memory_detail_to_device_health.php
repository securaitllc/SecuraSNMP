<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detailed memory signals for EdgeConnect/Linux appliances. "% used" is
 * misleading on Silver Peak (it reserves nearly all RAM by design); the real
 * health is the reclaimable memory (free + buffers + cached) and swap usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_health', function (Blueprint $table): void {
            $table->unsignedInteger('mem_reclaimable_mb')->nullable()->after('mem_pct');
            $table->unsignedInteger('swap_used_mb')->nullable()->after('mem_reclaimable_mb');
        });
    }

    public function down(): void
    {
        Schema::table('device_health', function (Blueprint $table): void {
            $table->dropColumn(['mem_reclaimable_mb', 'swap_used_mb']);
        });
    }
};
