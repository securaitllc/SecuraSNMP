<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the device calls ITSELF, kept beside what we call it.
 *
 * `devices.name` is the operator's label — it drives topology matching, alarm
 * subjects and every list in the UI. sysName is the appliance's own hostname, and
 * during an SD-WAN migration the two part ways silently: a replacement EdgeConnect
 * comes up at the same IP under a new hostname while Nodus keeps showing the name
 * of the box that left the rack.
 *
 * Kept as an OBSERVATION, never written straight into `name`. LldpCollector
 * resolves neighbours by device name, so a blind fleet-wide rename would re-wire
 * the topology with no record of what changed. Adoption is an explicit action —
 * one click for the whole fleet, but a click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('snmp_hostname')->nullable()->after('name');
            $table->timestamp('hostname_checked_at')->nullable()->after('snmp_hostname');
            $table->string('previous_name')->nullable()->after('hostname_checked_at');
            $table->timestamp('renamed_at')->nullable()->after('previous_name');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['snmp_hostname', 'hostname_checked_at', 'previous_name', 'renamed_at']);
        });
    }
};
