<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            // DHCP: only the public IP the site currently has (for monitoring),
            // no subnet/gateway. Static: full addressing.
            $table->string('ip_assignment')->default('static')->after('circuit_type'); // static | dhcp
            $table->string('gateway_ip')->nullable()->after('subnet');
            // The service contract is with the ISP, but the last-mile carrier (LEC)
            // is often a different company — track it for last-mile escalation.
            $table->string('lec_name')->nullable()->after('gateway_ip');
            $table->string('lec_circuit_id')->nullable()->after('lec_name');
            $table->string('lec_support_phone')->nullable()->after('lec_circuit_id');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn(['ip_assignment', 'gateway_ip', 'lec_name', 'lec_circuit_id', 'lec_support_phone']);
        });
    }
};
