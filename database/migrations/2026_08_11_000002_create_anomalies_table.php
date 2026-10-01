<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline-deviation anomalies — a NON-paging signal, separate from the alarm
 * stream. One open row per (entity, metric); resolved when the metric returns to
 * its own baseline. Conservative by design (sustained |z|>3), so it's an amber
 * "worth a look", not a critical page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type'); // 'interface' | 'circuit'
            $table->unsignedBigInteger('entity_id');
            $table->string('metric');      // 'throughput' | 'discards' | 'latency'
            $table->string('direction');   // 'spike' | 'drop'
            $table->double('baseline');    // median for that hour-of-day
            $table->double('observed');
            $table->double('z_score');
            $table->timestamp('detected_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'metric']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
