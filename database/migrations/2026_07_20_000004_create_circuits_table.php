<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('isp_name');
            $table->enum('circuit_type', ['fiber', 'cable']);
            $table->string('circuit_id');
            $table->string('account_number')->nullable();
            $table->string('support_phone')->nullable();
            $table->string('monitored_ip');
            $table->string('subnet')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['up', 'down'])->default('up');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuits');
    }
};
