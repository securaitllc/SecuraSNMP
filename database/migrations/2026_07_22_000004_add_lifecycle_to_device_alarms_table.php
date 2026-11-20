<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_alarms', function (Blueprint $table) {
            // 8-digit human-trackable ticket, assigned per occurrence.
            $table->string('ticket_number', 8)->nullable()->after('alarm_id');
            // Acknowledge + clear-with-notes workflow for the NOC.
            $table->timestamp('acknowledged_at')->nullable()->after('severity');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->text('ack_note')->nullable()->after('acknowledged_by');
            $table->foreignId('cleared_by')->nullable()->after('cleared_at')->constrained('users')->nullOnDelete();
            $table->text('clear_note')->nullable()->after('cleared_by');
            // True when a NOC cleared it (vs auto-cleared by the poller).
            $table->boolean('cleared_manually')->default(false)->after('clear_note');
            // Whether the appliance still reports the alarm as active. Drives the
            // "don't resurrect a manually-cleared alarm until it flaps" rule.
            $table->boolean('active_on_device')->default(true)->after('cleared_manually');

            $table->index('ticket_number');
        });
    }

    public function down(): void
    {
        Schema::table('device_alarms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('cleared_by');
            $table->dropColumn(['ticket_number', 'acknowledged_at', 'ack_note', 'clear_note', 'cleared_manually', 'active_on_device']);
        });
    }
};
