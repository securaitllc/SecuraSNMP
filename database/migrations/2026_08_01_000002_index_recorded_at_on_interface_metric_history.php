<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's fleet-wide 24h traffic total filters interface_metric_history by
 * recorded_at ALONE, which the existing (device_interface_id, recorded_at)
 * composite index can't serve (wrong leading column) — so it full-scanned a
 * table that has grown into the millions of rows, making the dashboard take
 * 5-10s. A standalone recorded_at index lets that window scan use the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interface_metric_history', function (Blueprint $table) {
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('interface_metric_history', function (Blueprint $table) {
            $table->dropIndex(['recorded_at']);
        });
    }
};
