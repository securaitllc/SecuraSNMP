<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device/circuit list sparklines load every recent response-time point in one
 * query (WHERE recorded_at >= last hour). The existing (device_id, recorded_at)
 * index can't serve that — recorded_at isn't the leading column — so it scanned
 * ~16k rows and took ~1.5s on Massey. A covering index led by recorded_at makes it
 * an index-only range scan (no row lookups): the fleet sparklines load in a blink.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_metric_history', function (Blueprint $table) {
            $table->index(['recorded_at', 'device_id', 'response_time_ms'], 'dmh_spark_idx');
        });
        Schema::table('circuit_metric_history', function (Blueprint $table) {
            $table->index(['recorded_at', 'circuit_id', 'response_time_ms'], 'cmh_spark_idx');
        });
    }

    public function down(): void
    {
        Schema::table('device_metric_history', function (Blueprint $table) {
            $table->dropIndex('dmh_spark_idx');
        });
        Schema::table('circuit_metric_history', function (Blueprint $table) {
            $table->dropIndex('cmh_spark_idx');
        });
    }
};
