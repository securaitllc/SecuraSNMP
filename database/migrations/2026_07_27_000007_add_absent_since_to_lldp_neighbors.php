<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep a port's history after the endpoint disconnects.
     *
     * Neighbors that dropped out of a switch's LLDP table were deleted, so the moment
     * a port went down the record of what had been on it was gone — exactly when an
     * operator needs it ("ge-0/0/10 is down, what was plugged into it?").
     *
     * Rows are now retained and stamped with when they stopped being reported.
     */
    public function up(): void
    {
        if (Schema::hasColumn('lldp_neighbors', 'absent_since')) {
            return;
        }

        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->timestamp('absent_since')->nullable()->after('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->dropColumn('absent_since');
        });
    }
};
