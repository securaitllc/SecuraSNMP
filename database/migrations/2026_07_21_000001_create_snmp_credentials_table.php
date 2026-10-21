<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snmp_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('snmp_version', ['v2c', 'v3'])->default('v2c');
            // Secrets are encrypted at rest via the model's casts.
            $table->text('snmp_community')->nullable();
            $table->string('snmp_v3_username')->nullable();
            $table->text('snmp_v3_auth_key')->nullable();
            $table->text('snmp_v3_priv_key')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_credentials');
    }
};
