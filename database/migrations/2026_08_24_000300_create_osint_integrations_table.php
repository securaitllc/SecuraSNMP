<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys for the super-admin OSINT tool (ipdata, VirusTotal, reverse-phone …).
 * One row per provider; the key is AES-encrypted at rest via the SafeEncrypted cast,
 * never in a .env or a log. Super-admin only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osint_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();     // ipdata | virustotal | phone | urlscan
            $table->text('api_key')->nullable();       // SafeEncrypted
            $table->json('meta')->nullable();          // provider-specific (e.g. phone provider choice)
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osint_integrations');
    }
};
