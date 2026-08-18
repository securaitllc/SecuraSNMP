<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('event', ['open', 'resolved']);
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('subject');
            $table->text('body')->nullable();
            $table->enum('status', ['sent', 'failed', 'suppressed']);
            $table->text('error')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
