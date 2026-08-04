<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VLAN becomes a string LABEL, not an int. Juniper exposes it two ways —
     * older EX gives an 802.1Q tag via jnxExVlanTag, ELS/Mist gives a VLAN NAME
     * via jnxL2ald (and encodes the fdbId as index<<16). A name ("ENDPOINTS") is
     * more useful than a number, so we store whichever the switch offers.
     * mac_addresses is derived data, so dropping/re-adding the column is safe.
     */
    public function up(): void
    {
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'mac', 'vlan']);
            $table->dropColumn('vlan');
        });
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->string('vlan', 64)->default('')->after('mac');
            $table->unique(['device_id', 'mac', 'vlan']);
        });
    }

    public function down(): void
    {
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'mac', 'vlan']);
            $table->dropColumn('vlan');
        });
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->unsignedInteger('vlan')->default(0)->after('mac');
            $table->unique(['device_id', 'mac', 'vlan']);
        });
    }
};
