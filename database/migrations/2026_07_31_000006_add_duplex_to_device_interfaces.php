<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link speed / duplex + change tracking. A duplex mismatch (half on a switch
 * port) or a speed downshift (a 1G port negotiating 100M) points at a cabling /
 * negotiation problem rather than the interface — so we record the current duplex
 * and stamp when speed or duplex last changed, with the previous value, to make
 * that visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->string('duplex')->nullable();          // full | half | unknown
            $table->string('prev_duplex')->nullable();
            $table->timestamp('duplex_changed_at')->nullable();
            $table->unsignedBigInteger('prev_speed_bps')->nullable();
            $table->timestamp('speed_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn(['duplex', 'prev_duplex', 'duplex_changed_at', 'prev_speed_bps', 'speed_changed_at']);
        });
    }
};
