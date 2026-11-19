<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every email Nodus sends to Lumen NORA, and what came back.
     *
     * NORA keeps context by email thread, so the outbound Message-ID is the key that
     * ties a reply — and the ticket number in it — back to the circuit. Stored
     * verbatim with the body that was sent, so "what did we tell Lumen and when" is a
     * record, not a memory.
     */
    public function up(): void
    {
        Schema::create('nora_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('circuit_isp_ticket_id')->nullable()->constrained('circuit_isp_tickets')->nullOnDelete();
            // open_ticket | check_service | status | escalate | close
            $table->string('kind', 24);
            $table->string('message_id')->unique();
            $table->string('subject');
            $table->text('body');
            $table->string('impact', 16)->nullable();
            $table->string('sent_by')->nullable();
            $table->timestamp('sent_at');
            // Filled by the inbox reader (Phase 2) or by hand from the reply.
            $table->string('ticket_number', 64)->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->text('reply_excerpt')->nullable();
            $table->timestamps();

            $table->index(['circuit_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nora_requests');
    }
};
