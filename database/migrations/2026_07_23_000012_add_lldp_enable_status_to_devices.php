<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Result of the last background LLDP-enable job so the device page can
            // show it without the operator having to wait on the SSH push.
            $table->string('lldp_enable_status')->nullable()->after('serial_number');
            $table->timestamp('lldp_enable_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['lldp_enable_status', 'lldp_enable_at']);
        });
    }
};
