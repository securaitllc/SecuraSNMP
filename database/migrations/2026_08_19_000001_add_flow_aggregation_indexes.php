<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the Flows-page aggregations. At ~140k flows/hour the overview and
 * time-series group-bys were full-scanning ~1M+ rows (48h retention → millions), timing
 * out the page. These let the range-by-time + group-by-app / (src,dst) reads come from
 * the index instead of the clustered table. Added INPLACE by InnoDB (online, non-blocking).
 *
 * IDEMPOTENT: MySQL DDL is not transactional, so a run that created the first index then
 * died before recording the migration (killed mid-build, or the second index conflicted)
 * leaves the index in place but the migration pending. Re-running then hits "Duplicate key
 * name" and `set -e` aborts the whole migrate step — silently blocking every later
 * migration. Guard each add on the live index list so the queue drains from any partial state.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = collect(Schema::getIndexes('flow_records'))->pluck('name')->all();

        Schema::table('flow_records', function (Blueprint $table) use ($existing) {
            // apps breakdown + time-series (range on recorded_at, read app+bytes covered).
            if (! in_array('flow_ts_app_idx', $existing, true)) {
                $table->index(['recorded_at', 'app', 'bytes'], 'flow_ts_app_idx');
            }
            // top talkers + conversation count (range on recorded_at, read src/dst/bytes).
            if (! in_array('flow_talker_idx', $existing, true)) {
                $table->index(['recorded_at', 'src_ip', 'dst_ip', 'bytes'], 'flow_talker_idx');
            }
        });
    }

    public function down(): void
    {
        $existing = collect(Schema::getIndexes('flow_records'))->pluck('name')->all();

        Schema::table('flow_records', function (Blueprint $table) use ($existing) {
            if (in_array('flow_ts_app_idx', $existing, true)) {
                $table->dropIndex('flow_ts_app_idx');
            }
            if (in_array('flow_talker_idx', $existing, true)) {
                $table->dropIndex('flow_talker_idx');
            }
        });
    }
};
