<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra LLDP-MIB fields so an unmanaged neighbor (Mist AP, PoE phone) can
        // be classified and shown hanging off the switch port it's on.
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->string('remote_sysdesc', 512)->nullable()->after('remote_sysname');
            $table->string('remote_chassis_id')->nullable()->after('remote_sysdesc');
            $table->string('remote_capabilities')->nullable()->after('remote_chassis_id'); // raw hex
            $table->string('neighbor_type')->nullable()->after('remote_capabilities'); // ap|phone|switch|router|other
        });
    }

    public function down(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->dropColumn(['remote_sysdesc', 'remote_chassis_id', 'remote_capabilities', 'neighbor_type']);
        });
    }
};
