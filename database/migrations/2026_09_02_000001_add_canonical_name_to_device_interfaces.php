<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The port's own name, alongside the label a human gave it.
 *
 * `if_name` is filled from ifDescr, which on most gear IS the port name
 * (GigabitEthernet0/1, ge-0/0/11). On EdgeConnect it is not: ifDescr there is a
 * free-text comment, so the appliance at site 868 stores its wan0 port as "BB,"
 * and its LAN sub-interfaces as ",Data" / ",Voice". The real name only ever
 * existed in ifName (ifXTable), which the poller read and then threw away — so
 * nothing in the database knew that port was wan0, and every lookup that asks
 * for a port BY NAME (circuit bandwidth attribution, the WAN-port picker) missed.
 *
 * Kept as a SECOND column rather than changing what `if_name` means: alarms,
 * LLDP port matching and every list in the UI are keyed off `if_name` today, and
 * a fleet-wide rename would move all of them at once for one bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->string('if_canonical_name')->nullable()->after('if_name');
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn('if_canonical_name');
        });
    }
};
