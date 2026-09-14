<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            // ifAdminStatus: 'up' = administratively enabled, 'down' = intentionally
            // disabled. An admin-down port that is oper-down is NOT a fault, so it
            // never alerts and is excluded from the down counts (avoids false
            // positives on the many unused ports of a large switch).
            $table->string('admin_status')->default('up')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn('admin_status');
        });
    }
};
