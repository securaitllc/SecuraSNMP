<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual Chassis members. A Juniper VC is ONE managed device (one IP, one row in
 * `devices`) but is physically several switches, each with its own serial. This
 * table holds each member so inventory/RMA has every serial and the NOC can see a
 * single dead member while the VC is still up on its management IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('member_id');          // VC member index (0–9)
            $table->string('serial_number')->nullable();
            $table->string('model')->nullable();
            // master / backup / linecard — plain string, NOT a DB enum (SQLite ignores
            // enum, MySQL rejects unknown values → 500s that pass local tests).
            $table->string('role')->nullable();
            $table->string('sw_version')->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            // present = in the VC table this poll; missing = was there, now gone (offline).
            $table->string('status')->default('present');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('absent_since')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_members');
    }
};
