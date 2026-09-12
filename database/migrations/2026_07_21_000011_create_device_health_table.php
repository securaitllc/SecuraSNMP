<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('cpu_pct', 5, 2)->nullable();
            $table->decimal('mem_pct', 5, 2)->nullable();
            $table->decimal('temperature_c', 6, 2)->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_health');
    }
};
