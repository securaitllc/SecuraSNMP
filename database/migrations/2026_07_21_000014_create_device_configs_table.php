<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->longText('content');
            $table->string('hash', 64);           // sha256 of content — drift detection
            $table->unsignedInteger('line_count')->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['device_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_configs');
    }
};
