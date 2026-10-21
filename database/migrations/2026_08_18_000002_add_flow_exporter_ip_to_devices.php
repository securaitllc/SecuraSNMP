<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device's sFlow/NetFlow agent-id can differ from the IP Nodus monitors it on — a
 * Juniper switch often exports sFlow from me0 (out-of-band mgmt, off the forwarding
 * plane) while Nodus polls it on irb.x. Store that exporter IP so flows attribute to
 * the right device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('flow_exporter_ip', 45)->nullable()->after('next_hop_ip')->index();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('flow_exporter_ip');
        });
    }
};
