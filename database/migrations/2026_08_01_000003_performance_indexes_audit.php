<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB performance audit — indexes for the hot read paths that were full-scanning
 * tables which grow unbounded (alert history, metric history, the interface fleet):
 *
 *  - Alert lifecycle: the dashboard + topology load every OPEN alert via
 *    whereNull('ended_at') / whereNull('cleared_at'); those columns were unindexed,
 *    so the scan grew with every alert ever recorded.
 *  - device_interfaces: the dashboard's down-count and the availability list filter
 *    on status / admin_status / alarm_suppressed over ~all interfaces in the fleet.
 *  - circuit_metric_history: the fleet-wide circuit sparkline filters recorded_at
 *    alone; the existing (circuit_id, recorded_at) index can't serve it. A
 *    recorded_at-led covering index makes it an index-only scan (as its own comment
 *    already assumed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_alarms', fn (Blueprint $t) => $t->index('cleared_at'));

        foreach (['interface_alerts', 'circuit_alerts', 'tunnel_alerts', 'next_hop_alerts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->index('ended_at'));
        }

        Schema::table('device_interfaces', function (Blueprint $t) {
            $t->index(['status', 'admin_status']);
            $t->index(['admin_status', 'alarm_suppressed']);
        });

        Schema::table('circuit_metric_history', fn (Blueprint $t) => $t->index(
            ['recorded_at', 'circuit_id', 'response_time_ms'], 'circuit_spark_idx'
        ));
    }

    public function down(): void
    {
        Schema::table('device_alarms', fn (Blueprint $t) => $t->dropIndex(['cleared_at']));

        foreach (['interface_alerts', 'circuit_alerts', 'tunnel_alerts', 'next_hop_alerts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['ended_at']));
        }

        Schema::table('device_interfaces', function (Blueprint $t) {
            $t->dropIndex(['status', 'admin_status']);
            $t->dropIndex(['admin_status', 'alarm_suppressed']);
        });

        Schema::table('circuit_metric_history', fn (Blueprint $t) => $t->dropIndex('circuit_spark_idx'));
    }
};
