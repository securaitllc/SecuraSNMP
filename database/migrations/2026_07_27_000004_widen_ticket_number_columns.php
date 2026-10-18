<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['device_alarms', 'interface_alerts', 'tunnel_alerts'];

    /**
     * Widen ticket_number so the prefixed format fits.
     *
     * ticket_number was VARCHAR(8), sized exactly for the old bare 8-digit number.
     * 'MSTK50288872' is 12 characters, so on MySQL with STRICT_TRANS_TABLES the
     * first insert of a new ticket would fail with error 1406 (Data too long).
     * SQLite does not enforce VARCHAR length, which is why that class of fault
     * reaches production without a local test ever noticing.
     *
     * This migration only widens a column. It writes no rows, renumbers nothing and
     * adds no constraint — deliberately, because the version that did those things
     * failed partway on a live database and prevented the application from starting.
     * An ALTER of this shape is safe to re-run and safe to roll back.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('ticket_number', 32)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not narrowing back to 8. Any ticket issued while this was
        // applied is longer than that, and truncating live ticket numbers to undo a
        // schema change would destroy the reference an operator is holding.
    }
};
