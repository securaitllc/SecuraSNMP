<?php

use App\Models\TunnelAlert;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tunnel_alerts', function (Blueprint $table) {
            // Same NOC workflow the device/interface alarms have: 8-digit ticket,
            // acknowledge, and manual clear-with-note (e.g. a tunnel to a peer that
            // was removed from the orchestrator). A manual clear sets ended_at; the
            // verifier only re-opens on a real up->down flap, so it won't resurrect.
            $table->string('ticket_number', 8)->nullable()->after('id');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ack_note')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('clear_note')->nullable();
            $table->boolean('cleared_manually')->default(false);
        });

        TunnelAlert::whereNull('ticket_number')->get()->each(
            fn (TunnelAlert $a) => $a->updateQuietly(['ticket_number' => TunnelAlert::generateTicketNumber()]),
        );

        // Every currently-open tunnel alert predates the first-seen-down fix, so it
        // is a false alarm from a tunnel that was already down when polling began
        // (e.g. a peer removed from the orchestrator). Close them; real up->down
        // flaps re-alarm correctly from here on.
        TunnelAlert::whereNull('ended_at')->update(['ended_at' => now(), 'cleared_manually' => true]);
    }

    public function down(): void
    {
        Schema::table('tunnel_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('cleared_by');
            $table->dropColumn(['ticket_number', 'acknowledged_at', 'ack_note', 'clear_note', 'cleared_manually']);
        });
    }
};
