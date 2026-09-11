<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A current ISP dispatch reference for the circuit, independent of any outage alert.
 * An SD-WAN transport degrade (IP-SLA / tunnel down) has NO open CircuitAlert to hang a
 * ticket on — the ping still answers — so the operator needs a place to log the ISP case
 * that shows on the alarm regardless of the ping state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->string('isp_ticket')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('isp_ticket');
        });
    }
};
