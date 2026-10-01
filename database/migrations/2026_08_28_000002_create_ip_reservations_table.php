<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Addresses recorded by hand, for the allocations no protocol reports.
     *
     * SNMP's ipAddrTable only returns addresses bound to an interface. A firewall's
     * VIPs, NAT pools and policy-mapped addresses consume real public space and appear
     * in none of it — at HQ, 66 usable public addresses with only 12 visible to SNMP.
     * Polling harder cannot close that gap; the remainder exists only in firewall
     * configuration and has to be written down.
     */
    public function up(): void
    {
        Schema::create('ip_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->unsignedTinyInteger('prefix_len')->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // The device it belongs to, when it is one we know. A NAT pool often
            // belongs to no device in particular, so this stays optional.
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->nullable();          // what it is, in plain words
            $table->string('purpose', 32)->default('reserved');  // vip | nat | host | gateway | reserved
            $table->string('assignment', 16)->default('static'); // static | dhcp
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            // One record per address. Two people reserving the same address is exactly
            // the collision this exists to prevent, so the database refuses it.
            $table->unique('ip');
            $table->index('site_id');
            $table->index('purpose');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_reservations');
    }
};
