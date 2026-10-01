<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The real mask for a LAN, where the inferred one is wrong.
     *
     * LAN ranges are observed rather than recorded: every private address an appliance
     * ARPs is bucketed into the /24 it falls in, because almost nothing on this fleet
     * is written down and a /24 is the safe assumption for a service centre.
     *
     * It is only an assumption. 10.11.0.0 is a /23, so the inference split it into
     * 10.11.0.0/24 and 10.11.1.0/24 — two half-empty ranges where there is one, each
     * reporting 254 usable addresses against a real 510, and a host in the upper half
     * reading as a neighbour rather than as part of the same LAN.
     *
     * ARP cannot tell us a mask. It reports who answered, never what the subnet is, so
     * no amount of polling closes this: the mask exists in a device's configuration and
     * has to be written down. A row here is that correction — an operator saying "this
     * block is a /23", which then governs how the addresses inside it are grouped and
     * how capacity is measured.
     */
    public function up(): void
    {
        Schema::create('ip_prefixes', function (Blueprint $table) {
            $table->id();
            // Stored in its canonical form (network address + length), so 10.11.0.5/23
            // and 10.11.1.9/23 cannot both exist as separate records of one prefix.
            $table->string('cidr', 49)->unique();
            // Optional: a prefix may belong to a site, or be fleet-wide (a supernet
            // an operator wants treated as one block wherever it appears).
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->nullable();   // what this block is, in plain words
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_prefixes');
    }
};
