<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuit_alerts', function (Blueprint $table) {
            // Why the outage happened: 'hard_down' (abrupt 100% loss) or
            // 'packet_loss' (a brownout of rising loss preceded it). Plain string,
            // never an enum — MySQL/SQLite enum divergence has bitten us before.
            $table->string('cause', 24)->nullable()->after('started_at');
            $table->unsignedTinyInteger('detected_loss_pct')->nullable()->after('cause');
        });
        Schema::table('circuit_metric_history', function (Blueprint $table) {
            $table->unsignedTinyInteger('loss_pct')->nullable()->after('response_time_ms');
        });
        Schema::table('circuits', function (Blueprint $table) {
            $table->unsignedTinyInteger('last_loss_pct')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('circuit_alerts', fn (Blueprint $t) => $t->dropColumn(['cause', 'detected_loss_pct']));
        Schema::table('circuit_metric_history', fn (Blueprint $t) => $t->dropColumn('loss_pct'));
        Schema::table('circuits', fn (Blueprint $t) => $t->dropColumn('last_loss_pct'));
    }
};
