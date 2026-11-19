<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_scans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // JSON array of CIDR targets, e.g. ["10.15.0.0/22","10.20.5.0/24"].
            $table->json('subnets');
            $table->foreignId('snmp_credential_id')->constrained()->cascadeOnDelete();
            // Who launched the scan — audit trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('hosts_total')->default(0);
            $table->unsignedInteger('hosts_responded')->default(0);
            $table->unsignedInteger('devices_found')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_scans');
    }
};
