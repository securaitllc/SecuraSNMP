<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP -> MAC from the SD-WAN edge ARP tables (ipNetToMediaPhysAddress), which we already
 * poll every LLDP cycle to resolve neighbours — this just persists that mapping so an
 * operator can trace an endpoint by IP or see the IP next to a learned MAC.
 *
 * Scoped by the edge (site): private LAN ranges repeat across the fleet, so the SAME ip
 * lives at many sites with different MACs — unique is (device_id, ip), never ip alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arp_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45);
            $table->string('mac', 17);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['device_id', 'ip']);
            $table->index(['mac', 'site_id']); // join FDB MACs to their site's ARP IP
            $table->index('ip');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arp_entries');
    }
};
