<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accountability trail for circuit contract renewals — every renewal is
     * stamped (who / when / old → new end date), never a silent overwrite of the
     * date on the circuit.
     */
    public function up(): void
    {
        Schema::create('circuit_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->date('previous_end_date')->nullable();
            $table->date('new_end_date');
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['circuit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_renewals');
    }
};
