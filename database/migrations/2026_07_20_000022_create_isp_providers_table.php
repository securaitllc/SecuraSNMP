<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isp_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('support_phone')->nullable();
            $table->string('account_rep_name')->nullable();
            $table->string('account_rep_mobile')->nullable();
            $table->string('account_rep_phone')->nullable();
            $table->string('account_rep_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_providers');
    }
};
