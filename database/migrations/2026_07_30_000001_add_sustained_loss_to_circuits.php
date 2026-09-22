<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Degraded" was decided from a single 5-probe ping, so ONE dropped probe (1/5 =
 * 20%) — normal internet jitter to a public IP — flagged a healthy circuit as
 * degraded. sustained_loss_pct is the median loss over the recent polls: a lone
 * spike among zeros leaves the median at 0, so only loss that actually persists
 * reads as degraded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->unsignedTinyInteger('sustained_loss_pct')->default(0)->after('last_loss_pct');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('sustained_loss_pct');
        });
    }
};
