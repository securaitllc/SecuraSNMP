<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['email', 'slack', 'webhook']);
            // Destination config (email address / webhook URL). Encrypted at rest.
            $table->text('config');
            $table->enum('min_severity', ['info', 'warning', 'critical'])->default('warning');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
