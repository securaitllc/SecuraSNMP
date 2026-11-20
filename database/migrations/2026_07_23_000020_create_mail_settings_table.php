<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton SMTP configuration set from the UI, overriding the env MAIL_* so an
 * operator can point Nodus at their mail relay (e.g. Office 365) without editing
 * the container environment. The password is stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(587);
            $table->string('encryption')->default('tls');   // 'tls' | 'ssl' | 'none'
            $table->string('username')->nullable();
            $table->text('password')->nullable();           // encrypted at rest
            $table->string('from_address')->nullable();
            $table->string('from_name')->default('Nodus Alerts');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
