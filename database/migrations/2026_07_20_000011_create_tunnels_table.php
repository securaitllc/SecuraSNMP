<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tunnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('tunnel_name');
            $table->enum('status', ['up', 'down'])->default('up');
            $table->unsignedBigInteger('in_discards')->default(0);
            $table->unsignedBigInteger('out_discards')->default(0);
            $table->unsignedBigInteger('in_discards_delta')->default(0);
            $table->unsignedBigInteger('out_discards_delta')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'tunnel_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnels');
    }
};
