<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // High-availability pairing: two (or more) devices that act as one logical
        // unit — HA SD-WAN, HA firewall. Members share an ha_group label; ha_role
        // marks which is active/standby (informational). A single member down while
        // a peer is up is degraded (redundancy holds), not a full outage.
        Schema::table('devices', function (Blueprint $table) {
            $table->string('ha_group')->nullable()->after('role')->index();
            $table->string('ha_role')->nullable()->after('ha_group'); // active | standby | null
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['ha_group', 'ha_role']);
        });
    }
};
