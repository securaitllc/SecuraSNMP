<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracted bandwidth for a circuit — the down/up speeds the ISP contract provides
 * (e.g. 300 / 20 Mbps). Distinct from the interface's negotiated link speed: the
 * contract is what you PAY for and hold the ISP to, and it drives utilisation-vs-
 * contract on the circuit view. Stored in Mbps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->unsignedInteger('contract_down_mbps')->nullable()->after('sla_target_pct');
            $table->unsignedInteger('contract_up_mbps')->nullable()->after('contract_down_mbps');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn(['contract_down_mbps', 'contract_up_mbps']);
        });
    }
};
