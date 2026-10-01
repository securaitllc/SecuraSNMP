<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When onboarding a switch, its many unused access ports (admin-up but no
        // cable) each read as an "interface down" alarm. Suppressing marks such a
        // port as an expected-down/false alarm so it drops out of the alert feed
        // and KPI. The poller auto-clears the flag if the port ever comes up, so a
        // genuine future outage on that port still alarms.
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->boolean('alarm_suppressed')->default(false)->after('admin_status');
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn('alarm_suppressed');
        });
    }
};
