<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tunnels', function (Blueprint $table) {
            // The remote peer this overlay tunnel terminates on (e.g. HUB-A-PRI)
            // and the hub it rolls up to (HUB-A / HUB-B) — parsed from the Silver
            // Peak tunnel name — so the topology can show tunnels per hub and alarm
            // per hub.
            $table->string('peer')->nullable()->after('tunnel_name');
            $table->string('hub')->nullable()->after('peer');
        });
    }

    public function down(): void
    {
        Schema::table('tunnels', function (Blueprint $table) {
            $table->dropColumn(['peer', 'hub']);
        });
    }
};
