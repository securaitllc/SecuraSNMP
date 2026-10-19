<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the gateway's neighbour table says about the entry, not just that it exists.
     *
     * `show arp` on an EdgeConnect prints a state on every line, and on a healthy
     * appliance almost all of them read STALE — a cached mapping the kernel has not
     * revalidated — with only the WAN gateways REACHABLE. ipNetToMediaPhysAddress, which
     * the collector walks, carries no state at all, so every one of those was read as a
     * confirmed host. #024 showed "1 / 254 confirmed" for a stale DHCP lease on a phone
     * that also holds a real address on the site LAN.
     *
     * ipNetToPhysicalState (IP-MIB) is the same value the CLI prints: reachable(1),
     * stale(2), delay(3), probe(4), invalid(5), unknown(6), incomplete(7). Nullable
     * because not every appliance answers that table, and an unanswered walk must leave
     * the column unknown rather than invent a state.
     */
    public function up(): void
    {
        Schema::table('arp_entries', function (Blueprint $table) {
            $table->string('state', 16)->nullable()->after('interface');
        });
    }

    public function down(): void
    {
        Schema::table('arp_entries', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
