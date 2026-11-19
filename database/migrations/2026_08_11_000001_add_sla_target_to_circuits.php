<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-circuit SLA target. Null = fall back to the by-type default in the report
 * (fiber 99.9 / cable 99.5 / lte 99.0), so existing circuits work with no edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->decimal('sla_target_pct', 5, 2)->nullable()->after('monitoring_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('sla_target_pct');
        });
    }
};
