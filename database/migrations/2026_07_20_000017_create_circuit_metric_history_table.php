<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuit_metric_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            // Null response time means the ping timed out / the circuit was unreachable.
            $table->decimal('response_time_ms', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['circuit_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_metric_history');
    }
};
