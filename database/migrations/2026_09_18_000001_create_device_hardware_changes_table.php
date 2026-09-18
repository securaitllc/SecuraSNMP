<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trail of what a device used to be.
 *
 * `devices.previous_serial_number` holds exactly one prior value, so the second
 * swap erases the first: an appliance RMA'd twice reads as though it were only
 * ever replaced once, and the unit in between vanishes from the record. Inventory,
 * warranty and "when did this box actually change" questions all need the whole
 * sequence, not the last hop.
 *
 * Covers name as well as serial. A console rename and a hardware replacement look
 * identical from the outside until you can see whether the serial moved with the
 * name, which is exactly the question the hostname-drift review asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_hardware_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Which attribute moved: serial_number, name, model, os_version.
            $table->string('field', 32);
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();

            // 'snmp' = the box told us; 'operator' = someone adopted a hostname or
            // edited the record. Both belong in the trail, and telling them apart is
            // the difference between "the hardware changed" and "we corrected a typo".
            $table->string('source', 16)->default('snmp');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['device_id', 'detected_at']);
            $table->index(['field', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_hardware_changes');
    }
};
