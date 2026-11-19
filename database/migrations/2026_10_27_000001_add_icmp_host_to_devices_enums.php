<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ICMP-only monitoring: a device that is just an address to ping (a cloud
     * public IP, an SD-WAN peer, a third-party box) — vendor `other`, role `host`.
     * Those fall outside every SNMP/SSH poller's selection, so only devices:monitor
     * (reachability) touches them.
     *
     * The columns become plain strings: DB enums are the SQLite-passes/MySQL-500s
     * trap, and the allowed values already live in DeviceRequest's `in:` rules.
     * Retyped in place with ->change() — never drop + re-add (FK trap).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('vendor', 32)->change();
            $table->string('role', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->enum('vendor', ['juniper', 'silverpeak', 'fortigate'])->change();
            $table->enum('role', ['switch', 'edgeconnect', 'firewall'])->change();
        });
    }
};
