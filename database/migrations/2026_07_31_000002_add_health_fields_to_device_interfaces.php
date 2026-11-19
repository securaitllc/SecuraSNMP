<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proactive interface health: operator annotations + acknowledgement baselines so
 * a "CRC errors / discards / flapping" pill can be acted on (note, ack, mute)
 * without polluting the fleet alarm stream, plus cheap poller-maintained counters
 * (flap count, last error/discard time) that avoid an expensive history scan on
 * every panel load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            // Operator annotation — "known bad SFP, RMA #123" — visible to the team.
            $table->text('note')->nullable();
            $table->string('note_by')->nullable();
            $table->timestamp('note_at')->nullable();

            // Health acknowledgement: acking stamps health_ack_at. A condition
            // re-arms only when a NEWER fault lands (last_error_at/last_discard_at/
            // last_flap_at > health_ack_at), so no counter baseline is needed.
            $table->timestamp('health_ack_at')->nullable();
            $table->string('health_ack_by')->nullable();

            // Poller-maintained so health is a cheap row read, not a history scan.
            $table->unsignedInteger('flap_count')->default(0);
            $table->timestamp('last_flap_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_discard_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn([
                'note', 'note_by', 'note_at',
                'health_ack_at', 'health_ack_by', 'health_ack_errors', 'health_ack_discards',
                'flap_count', 'last_flap_at', 'last_error_at', 'last_discard_at',
            ]);
        });
    }
};
