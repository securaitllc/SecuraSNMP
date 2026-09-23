<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A whole subnet held for a site that does not exist yet.
     *
     * Everything else in IPAM is occupancy: an address answered, a device is addressed
     * there, an operator wrote a NAT pool down. All of it describes what IS. None of it
     * can describe what is ABOUT to be, and that is the one thing a planner needs —
     * space allocated on paper for a service centre whose kit has not shipped.
     *
     * Until now the only way to hold a block was to record a single address inside it,
     * which flipped the /24 from `free` to `used` and read, ever after, as a live
     * subnet with one host in it. Nobody could tell a deliberate hold from a range with
     * one stray ARP entry, and nothing said who held it, for what, or until when.
     *
     * A row here is the hold itself. It names the block, not an address in it.
     *
     * `site_id` is nullable ON PURPOSE and usually null: the site record is created
     * when the location opens, and the address space is claimed months earlier.
     * `site_label` carries the name in the meantime so a held block is never anonymous.
     */
    public function up(): void
    {
        Schema::create('subnet_reservations', function (Blueprint $table) {
            $table->id();
            // Canonical network form, so 10.200.180.5/24 and 10.200.180.9/24 cannot
            // arrive as separate holds on one block.
            //
            // NOT unique, deliberately. A block is held, released when the plan
            // changes, and legitimately held again later for a different site — three
            // rows for one CIDR, and only the last is in force. The constraint that is
            // actually wanted is "unique among rows where released_at IS NULL", which
            // no portable index expresses: MySQL 8 has no partial index, and both
            // engines treat NULLs in a unique index as distinct, so a composite on
            // (cidr, released_at) would let two live holds through. A partial index
            // that works on SQLite and not on MySQL is precisely the divergence this
            // codebase has shipped to production before. Uniqueness among held rows is
            // enforced by the overlap check in SubnetReservationController, which
            // covers exact duplicates and containment in one test.
            $table->string('cidr', 49);
            $table->index('cidr');
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // What the site will be called, before there is a site row to point at.
            $table->string('site_label')->nullable();
            $table->string('label')->nullable();
            // When the location is expected to go live. A hold whose date has passed
            // and that was never released is the thing worth chasing.
            $table->date('planned_for')->nullable();
            // Released rather than deleted: which blocks were held and handed back is
            // exactly the history a planner needs, and a DELETE destroys it.
            $table->timestamp('released_at')->nullable();
            $table->string('released_by')->nullable();
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subnet_reservations');
    }
};
