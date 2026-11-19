<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Next-hop (gateway) alerts were the one alert type with no manual ack/clear: the
 * table had only started_at/ended_at. The unified Alarm Console clears every type,
 * so give next-hop alerts the same accountability columns as device/tunnel/
 * interface/circuit alerts — who cleared it, when, why.
 *
 * A manual clear on a next-hop alert only sets ended_at; if the gateway is still
 * genuinely unreachable the poller honestly reopens a fresh alert next sweep (by
 * design — a real outage is never hidden). cleared_manually is recorded for the
 * audit trail and to tell an operator clear from an automatic recovery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('next_hop_alerts', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('ended_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->text('ack_note')->nullable()->after('acknowledged_by');
            $table->foreignId('cleared_by')->nullable()->after('ack_note')->constrained('users')->nullOnDelete();
            $table->text('clear_note')->nullable()->after('cleared_by');
            $table->boolean('cleared_manually')->default(false)->after('clear_note');
        });
    }

    public function down(): void
    {
        Schema::table('next_hop_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('cleared_by');
            $table->dropColumn(['acknowledged_at', 'ack_note', 'clear_note', 'cleared_manually']);
        });
    }
};
