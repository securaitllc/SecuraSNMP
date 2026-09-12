<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indicators of Compromise collected during an investigation, optionally attached to a
 * case. Type is a plain string (SQLite/MySQL enum-parity rule): domain|host|ip|url|
 * email|phone|asn|cert|hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osint_iocs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained('osint_cases')->cascadeOnDelete();
            $table->string('type');
            $table->string('value');
            $table->string('confidence')->default('medium'); // low|medium|high
            $table->string('source')->nullable();             // whois|dns|crt.sh|ipdata|ipqs|manual
            $table->json('context')->nullable();              // raw enrichment snippet
            $table->timestamp('first_seen')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['case_id', 'type']);
            $table->unique(['case_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osint_iocs');
    }
};
