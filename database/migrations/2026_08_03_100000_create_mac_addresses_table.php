<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Learned MAC history: one upserted row per (device, mac, vlan) — NOT a
     * time-series — so the table grows with distinct endpoints seen, not with
     * time. last_seen_at is bumped each poll; a retention prune drops rows not
     * seen in N days. Keeps "what was last on this (now-down) port" queryable.
     */
    public function up(): void
    {
        Schema::create('mac_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_interface_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mac', 17);            // AA:BB:CC:DD:EE:FF
            $table->unsignedInteger('vlan')->default(0);
            $table->string('oui_vendor')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['device_id', 'mac', 'vlan']);
            $table->index('mac');
            $table->index('device_interface_id');
            $table->index('oui_vendor');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mac_addresses');
    }
};
