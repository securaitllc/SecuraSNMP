<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snmp_traps', function (Blueprint $table) {
            $table->id();
            // Nullable: a trap may arrive from a source IP that matches no
            // known device — we still record it.
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_ip');
            $table->string('trap_oid')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['device_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_traps');
    }
};
