<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('ip_address');
            $table->enum('vendor', ['juniper', 'silverpeak']);
            $table->string('model');
            $table->enum('role', ['switch', 'edgeconnect']);
            $table->enum('snmp_version', ['v2c', 'v3'])->nullable();
            $table->text('snmp_community')->nullable();
            $table->string('snmp_v3_username')->nullable();
            $table->text('snmp_v3_auth_key')->nullable();
            $table->text('snmp_v3_priv_key')->nullable();
            $table->string('ssh_username')->nullable();
            $table->text('ssh_credential')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
