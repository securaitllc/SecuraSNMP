<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every ISP ticket raised on a circuit, and what it was raised for.
     *
     * `circuits.isp_ticket` is one overwritable string. It answers "what is the ticket
     * number right now" and nothing else: type a second one and the first is gone, with
     * no record that it ever existed, who entered it, when, or what it was about. An
     * operator who logged a ticket against a packet-loss finding had no way afterwards
     * to find it, show it to anybody, or answer "how many tickets have we raised on this
     * circuit this quarter".
     *
     * The audit log does not cover it either — it stores method, path, status and user,
     * so it proves somebody POSTed to /isp-ticket and not what they typed.
     *
     * The live field on the circuit stays exactly as it is; it is what the dashboard,
     * the circuits page and the wallboard read. This is the trail behind it.
     */
    public function up(): void
    {
        Schema::create('circuit_isp_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_number', 100);

            // What the operator was looking at when they raised it. Free text rather
            // than a foreign key: the finding that prompted the call may be resolved,
            // re-opened or pruned long before the carrier answers, and the reason must
            // outlive it.
            $table->string('reason')->nullable();
            // The anomaly it was raised from, when it was raised from one. Nullable and
            // not constrained for the same reason — this is provenance, not ownership.
            $table->unsignedBigInteger('anomaly_id')->nullable();

            $table->timestamp('opened_at');
            $table->string('opened_by')->nullable();
            // Closed when the number is cleared or replaced. Kept, never deleted.
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['circuit_id', 'opened_at']);
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_isp_tickets');
    }
};
