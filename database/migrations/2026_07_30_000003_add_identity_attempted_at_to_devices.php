<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the identity poll last ran for a device. Identity discovery (model / serial /
 * OS over SNMP) now retries until ALL three are known, so a hand-entered model at
 * add-time no longer blocks serial/OS. This timestamp throttles that retry (a device
 * that never exposes a serial must not re-walk every cycle — the snmpwalk storm the
 * old model-only gate was there to prevent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('identity_attempted_at')->nullable()->after('os_version');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('identity_attempted_at');
        });
    }
};
