<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the Flows-page aggregations. At ~140k flows/hour the overview and
 * time-series group-bys were full-scanning ~1M+ rows (48h retention → millions), timing
 * out the page. These let the range-by-time + group-by-app / (src,dst) reads come from
 * the index instead of the clustered table. Added INPLACE by InnoDB (online, non-blocking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_records', function (Blueprint $table) {
            // apps breakdown + time-series (range on recorded_at, read app+bytes covered).
            $table->index(['recorded_at', 'app', 'bytes'], 'flow_ts_app_idx');
            // top talkers + conversation count (range on recorded_at, read src/dst/bytes).
            $table->index(['recorded_at', 'src_ip', 'dst_ip', 'bytes'], 'flow_talker_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flow_records', function (Blueprint $table) {
            $table->dropIndex('flow_ts_app_idx');
            $table->dropIndex('flow_talker_idx');
        });
    }
};
