<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the current loss episode began.
     *
     * "Since when?" is the first thing a carrier asks and the app had no answer. The
     * history table held it — every poll for thirty days — but nothing read it back,
     * so an operator on the phone had to scroll a graph and guess.
     *
     * Set by the poller at the moment a circuit first crosses the loss-frequency line,
     * cleared when it drops back under. A timestamp written when the crossing
     * happened, not reconstructed afterwards.
     */
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->timestamp('degraded_since')->nullable()->after('loss_peak_pct');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('degraded_since');
        });
    }
};
