<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Per-appliance next-hops as the Silver Peak reports them (show system
    // nexthops): one row per WAN uplink gateway, with the SP's own reachability.
    // Replaces the single devices.next_hop_ip with the real N-per-SP set.
    public function up(): void
    {
        Schema::create('device_next_hops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address');
            $table->string('interface')->nullable();        // wan0, wan1
            $table->string('reachability')->nullable();      // reachable | unreachable (from the SP)
            $table->string('uptime')->nullable();
            $table->string('status')->default('up');         // up | down (derived from reachability)
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'ip_address']);
        });

        Schema::table('next_hop_alerts', function (Blueprint $table) {
            $table->foreignId('device_next_hop_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('next_hop_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_next_hop_id');
        });
        Schema::dropIfExists('device_next_hops');
    }
};
