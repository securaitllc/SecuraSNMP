<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface-down alerts carry a severity: a regular access port is a WARNING,
 * an uplink to another switch or an SD-WAN appliance is CRITICAL (it takes a
 * whole segment or site off the network).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interface_alerts', function (Blueprint $table): void {
            $table->string('severity')->default('warning')->after('device_interface_id');
        });
    }

    public function down(): void
    {
        Schema::table('interface_alerts', function (Blueprint $table): void {
            $table->dropColumn('severity');
        });
    }
};
