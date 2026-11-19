<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which interface the gateway learned this neighbour on.
     *
     * ipNetToMediaPhysAddress is indexed ifIndex.a.b.c.d, and the collector parsed the
     * ifIndex out of the OID and threw it away. Every ARP entry therefore looked
     * identical whether the gateway learned it on a LAN port or on its WAN uplink.
     *
     * 192.168.100.1 is the DOCSIS cable-modem management address, so every site's ISP
     * modem sat in these tables looking exactly like a host on a site LAN. An operator
     * reading the range detail saw Commscope, Vantiva and Netgear MACs and was one
     * click from containing a service centre over it.
     *
     * Keeping the ifIndex — and resolving it to the port name the interface poller
     * already holds — is what lets the screen say "learned on wan0" instead of leaving
     * the reader to guess.
     */
    public function up(): void
    {
        Schema::table('arp_entries', function (Blueprint $table) {
            $table->unsignedInteger('if_index')->nullable()->after('mac');
            $table->string('interface', 64)->nullable()->after('if_index');
        });
    }

    public function down(): void
    {
        Schema::table('arp_entries', function (Blueprint $table) {
            $table->dropColumn(['if_index', 'interface']);
        });
    }
};
