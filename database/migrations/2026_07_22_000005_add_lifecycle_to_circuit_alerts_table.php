<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Give a circuit outage the same NOC lifecycle a device alarm has: a NOC can
    // acknowledge it while working the ISP, record the ISP-provided ticket, and
    // manually clear it (false positive / maintenance) with a note. ticket_number
    // already exists on this table.
    public function up(): void
    {
        Schema::table('circuit_alerts', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('ticket_number');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->text('ack_note')->nullable()->after('acknowledged_by');
            $table->foreignId('cleared_by')->nullable()->after('ack_note')->constrained('users')->nullOnDelete();
            $table->text('clear_note')->nullable()->after('cleared_by');
            // A NOC-initiated close (vs. the monitor closing it on recovery). Kept
            // distinct so the monitor's flap logic doesn't re-alert a hand-cleared one.
            $table->boolean('cleared_manually')->default(false)->after('clear_note');
        });
    }

    public function down(): void
    {
        Schema::table('circuit_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('cleared_by');
            $table->dropColumn(['acknowledged_at', 'ack_note', 'clear_note', 'cleared_manually']);
        });
    }
};
