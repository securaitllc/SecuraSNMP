<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('if_index');
            $table->string('if_name');
            $table->enum('status', ['up', 'down'])->default('up');
            $table->unsignedBigInteger('in_octets')->default(0);
            $table->unsignedBigInteger('out_octets')->default(0);
            $table->unsignedBigInteger('in_discards')->default(0);
            $table->unsignedBigInteger('out_discards')->default(0);
            $table->unsignedBigInteger('in_discards_delta')->default(0);
            $table->unsignedBigInteger('out_discards_delta')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'if_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_interfaces');
    }
};
