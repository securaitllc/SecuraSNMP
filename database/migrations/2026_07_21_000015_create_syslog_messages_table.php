<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syslog_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_ip');
            $table->unsignedTinyInteger('facility')->nullable();
            $table->unsignedTinyInteger('severity')->nullable();
            $table->string('hostname')->nullable();
            $table->text('message');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index('received_at');
            $table->index(['device_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syslog_messages');
    }
};
