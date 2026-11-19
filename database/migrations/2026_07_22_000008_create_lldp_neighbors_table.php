<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Discovered layer-2 adjacencies (LLDP). Each row is one neighbor a device
    // reported: the local port, the remote system's name/port, and — when the
    // neighbor matches a device we monitor — the resolved remote device. The
    // topology draws real links from these instead of inferring by co-location.
    public function up(): void
    {
        Schema::create('lldp_neighbors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('local_port')->nullable();
            $table->string('remote_sysname')->nullable();
            $table->string('remote_port')->nullable();
            $table->foreignId('remote_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index('device_id');
            $table->index('remote_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lldp_neighbors');
    }
};
