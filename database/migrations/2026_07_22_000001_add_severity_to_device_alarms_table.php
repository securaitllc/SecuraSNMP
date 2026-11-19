<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_alarms', function (Blueprint $table) {
            // 'critical' (red) | 'warning' (amber) | 'info'. Reflects the
            // appliance-reported severity so the NOC can triage at a glance.
            $table->string('severity')->default('warning')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('device_alarms', function (Blueprint $table) {
            $table->dropColumn('severity');
        });
    }
};
