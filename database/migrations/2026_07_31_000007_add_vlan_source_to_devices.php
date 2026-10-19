<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which VLAN source a switch answers on ('jnx' = Juniper VLAN MIB,
 * 'qbridge' = Q-BRIDGE) so a transient empty SNMP walk (this gear drops
 * responses under high memory) can't flip a switch between id spaces and flap
 * every VLAN row active/inactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('vlan_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('vlan_source');
        });
    }
};
