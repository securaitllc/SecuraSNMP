<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-verify a device's hardware identity after it comes back from down.
 *
 * SnmpIdentityPoller is write-once by design: once model, serial and OS are known it
 * returns immediately, which is what stops a fleet-wide snmpwalk storm. The cost is
 * that a REPLACED device keeps the dead unit's serial forever — and during an SD-WAN
 * migration or a switch swap that is the normal case, not an edge case: the box goes
 * unreachable, an engineer racks different hardware, it comes back, and the app still
 * shows the serial of the unit now sitting in a box.
 *
 * A recovery is the honest trigger: the only moment a device can plausibly have become
 * different hardware is after it was away. `identity_recheck_at` is stamped when an
 * unreachable alarm actually clears, and it is the one condition under which the poller
 * will OVERWRITE identity rather than only fill blanks.
 *
 * `previous_serial_number` + `hardware_changed_at` keep the swap on the record. A serial
 * that changes silently is an inventory story nobody can reconstruct later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'identity_recheck_at')) {
                $table->timestamp('identity_recheck_at')->nullable();
            }
            if (! Schema::hasColumn('devices', 'previous_serial_number')) {
                $table->string('previous_serial_number')->nullable();
            }
            if (! Schema::hasColumn('devices', 'hardware_changed_at')) {
                $table->timestamp('hardware_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn([
            'identity_recheck_at', 'previous_serial_number', 'hardware_changed_at',
        ]));
    }
};
