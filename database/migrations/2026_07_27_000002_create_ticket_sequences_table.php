<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One counter row per ticket type, so numbers are sequential instead of random.
     *
     * The old generator picked a random 8-digit number, checked whether it already
     * existed, and inserted. With three poller loops running concurrently and no
     * unique constraint behind the check, two callers could pass it simultaneously
     * and both write. This table plus the unique indexes below close that.
     */
    public function up(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            // ALM / IFC / TUN — the type segment of the ticket number.
            $table->string('type', 8)->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sequences');
    }
};
