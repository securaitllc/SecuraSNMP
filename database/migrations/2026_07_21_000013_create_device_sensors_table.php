<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sensors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // celsius / rpm / voltsDC / voltsAC / watts / amperes / percentRH ...
            $table->string('sensor_type');
            $table->decimal('value', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('status')->default('ok');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sensors');
    }
};
