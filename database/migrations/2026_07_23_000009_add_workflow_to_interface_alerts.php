<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interface_alerts', function (Blueprint $table) {
            // Same NOC workflow the device alarms have: an 8-digit tracking ticket,
            // acknowledge, and manual clear-with-note. A manual clear sets ended_at;
            // the poller only re-opens on a real up->down flap, so it won't resurrect.
            $table->string('ticket_number', 8)->nullable()->after('id');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ack_note')->nullable();
            // Manual clear reuses ended_at (the alert's close time); these record who
            // and why. cleared_manually distinguishes an operator clear from the
            // poller auto-closing when the port came back up.
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('clear_note')->nullable();
            $table->boolean('cleared_manually')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('interface_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('cleared_by');
            $table->dropColumn(['ticket_number', 'acknowledged_at', 'ack_note', 'clear_note', 'cleared_manually']);
        });
    }
};
