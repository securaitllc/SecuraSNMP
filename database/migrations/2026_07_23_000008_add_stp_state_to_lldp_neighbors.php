<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            // Spanning-tree state of the LOCAL port this neighbour is on
            // (dot1dStpPortState): 1 disabled, 2 blocking, 3 listening, 4 learning,
            // 5 forwarding, 6 broken. Null when the switch exposes no STP MIB.
            $table->unsignedTinyInteger('stp_state')->nullable()->after('remote_mgmt_addr');
        });
    }

    public function down(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->dropColumn('stp_state');
        });
    }
};
