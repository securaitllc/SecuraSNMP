<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current ISP field-dispatch ETA + note on the circuit, alongside the ISP ticket.
 * Like isp_ticket, it lives on the circuit (not an outage alert) so it works for an
 * SD-WAN transport-degraded alarm that has no open CircuitAlert — and so the dashboard
 * and the circuits page read/write one source of truth. Idempotent guard: the migrate
 * queue has been fragile on prod, so never fail a re-run on an already-present column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            if (! Schema::hasColumn('circuits', 'dispatch_at')) {
                $table->timestamp('dispatch_at')->nullable()->after('isp_ticket');
            }
            if (! Schema::hasColumn('circuits', 'dispatch_note')) {
                $table->text('dispatch_note')->nullable()->after('dispatch_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn(['dispatch_at', 'dispatch_note']);
        });
    }
};
