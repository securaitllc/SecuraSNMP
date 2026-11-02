<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of every OSINT lookup — who queried what, when, and a short result note.
 * OSINT queries touch external services and staff PII, so each one is provable after the
 * fact. Separate from the general audit_logs so retention/redaction can differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osint_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');        // domain|ip|phone|subdomains
            $table->string('target');
            $table->string('verdict')->nullable();  // clean|suspicious|malicious|error
            $table->string('summary')->nullable();
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osint_lookups');
    }
};
