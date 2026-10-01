<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tunnel_metric_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tunnel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->enum('status', ['up', 'down']);
            $table->bigInteger('in_discards_delta');
            $table->bigInteger('out_discards_delta');
            $table->timestamps();

            $table->index(['tunnel_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnel_metric_history');
    }
};
