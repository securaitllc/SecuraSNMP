<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standardize every circuit's SLA uptime target to 99.5% (per the NOC's contractual
 * baseline — one target across the fleet rather than per-type). Sets the explicit column
 * on all existing circuits; new-circuit defaults are 99.5 in the form + report fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('circuits')->update(['sla_target_pct' => 99.5]);
    }

    public function down(): void
    {
        // No-op: prior per-circuit targets aren't recorded, so there's nothing to restore.
    }
};
