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
        // In-place type change — keeps the (device_id,mac,vlan) unique index and the
        // device_id FK intact. Dropping the index instead fails: MySQL needs it for
        // the foreign key ("Cannot drop index … needed in a foreign key constraint").
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->string('vlan', 64)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->unsignedInteger('vlan')->default(0)->change();
        });
    }
};
