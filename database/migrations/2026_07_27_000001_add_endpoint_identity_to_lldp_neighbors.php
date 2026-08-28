<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promote endpoint identity out of the advertised display string.
     *
     * LLDP already carries who is on a port, but the useful parts were buried in
     * free text and an undecoded chassis id:
     *
     *   - A Mitel handset advertises its system name as "regDN <ext>,MINET_<model>",
     *     so the extension and model were being rendered and then discarded.
     *   - lldpRemChassisId holds a MAC for some endpoint classes and an IP address
     *     for others, depending on the advertised subtype. Stored raw, it was
     *     unusable for either.
     *
     * These columns make the port -> endpoint mapping queryable, which is the
     * foundation the rest of the endpoint-history work builds on.
     */
    public function up(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            // Normalised AA:BB:CC:DD:EE:FF, set only when the neighbour advertises a
            // MAC chassis id. Indexed because "find this MAC" is the whole point.
            $table->string('remote_mac', 17)->nullable()->after('remote_chassis_id')->index();
            // Telephony extension (Mitel regDN). Indexed so an operator can search by
            // the number a user actually quotes when they call.
            $table->string('extension', 32)->nullable()->after('remote_mac')->index();
            // Human-readable endpoint model, e.g. "Mitel 6920".
            $table->string('endpoint_model', 64)->nullable()->after('extension');
        });
    }

    public function down(): void
    {
        Schema::table('lldp_neighbors', function (Blueprint $table) {
            $table->dropIndex(['remote_mac']);
            $table->dropIndex(['extension']);
            $table->dropColumn(['remote_mac', 'extension', 'endpoint_model']);
        });
    }
};
