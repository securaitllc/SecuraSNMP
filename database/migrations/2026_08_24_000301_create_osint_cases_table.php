<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An OSINT investigation case — the ticket built from a phishing/smishing investigation.
 * Holds the narrative, severity, status, MITRE ATT&CK mapping and owner; the IoCs and
 * timeline hang off it. Super-admin only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osint_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number')->unique();   // CASE-2026-0042
            $table->string('title');
            $table->string('severity')->default('medium'); // low|medium|high|critical (string, not enum — SQLite/MySQL parity)
            $table->string('status')->default('open');      // open|investigating|contained|closed
            $table->text('summary')->nullable();
            $table->json('mitre')->nullable();          // ["T1566","T1598"]
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osint_cases');
    }
};
