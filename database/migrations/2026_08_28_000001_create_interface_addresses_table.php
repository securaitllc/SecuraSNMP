<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The addresses configured ON a device's own interfaces.
     *
     * Distinct from arp_entries, which holds the NEIGHBOURS a device has talked to.
     * Neither devices.ip_address (one management address) nor ARP can tell you which
     * public address sits on which WAN port of an HA pair or a firewall — which is
     * exactly what has to be known before anyone hands out another one.
     *
     * A separate table rather than a column on device_interfaces because one interface
     * legitimately carries several addresses: secondaries, VIPs, and the shared address
     * of an HA pair.
     */
    public function up(): void
    {
        Schema::create('interface_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // Resolved by ifIndex where the interface is known; the address is still
            // worth recording when it is not, so this stays nullable.
            $table->foreignId('device_interface_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('if_index')->nullable();
            $table->string('ip', 45);
            $table->unsignedTinyInteger('prefix_len')->nullable();
            $table->string('netmask', 15)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['device_id', 'ip']);
            $table->index('ip');
            $table->index('is_public');
            $table->index('device_interface_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interface_addresses');
    }
};
