<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a MAC stops appearing in a switch's forwarding table we KNOW it left — the
 * poll succeeded and did not list it. Until now nothing recorded that, so a row sat
 * unchanged for the full 90-day retention and every reader had to guess from
 * last_seen_at whether the endpoint was still plugged in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->timestamp('absent_since')->nullable()->after('last_seen_at');
            $table->index('absent_since');
        });
    }

    public function down(): void
    {
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->dropIndex(['absent_since']);
            $table->dropColumn('absent_since');
        });
    }
};
