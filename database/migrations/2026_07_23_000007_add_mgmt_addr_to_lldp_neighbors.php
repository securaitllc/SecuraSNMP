<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            // The management IP a neighbor advertises over LLDP (lldpRemManAddr),
            // e.g. a Mitel phone or Mist AP's address.
            $table->string('remote_mgmt_addr')->nullable()->after('remote_port');
        });
    }

    public function down(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->dropColumn('remote_mgmt_addr');
        });
    }
};
