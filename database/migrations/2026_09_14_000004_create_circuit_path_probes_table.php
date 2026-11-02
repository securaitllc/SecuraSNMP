<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where along the path a circuit is losing packets.
     *
     * A circuit poll says whether packets reach the far end. It cannot say where they
     * stopped. When #037's DIA was losing on half its probes, the carrier's first
     * question was which hop — and the answer (ae31-527.bar3.orlando1.level3.net) had
     * to be worked out live on a call, because nothing here had ever looked.
     *
     * A path probe is an mtr run from the collector to the circuit's monitored address,
     * recorded hop by hop: loss, sent, average and worst RTT. Two things make it worth
     * keeping rather than running on demand:
     *
     *  - it is captured automatically the moment a circuit becomes lossy, while the
     *    problem is happening. A trace run an hour later, after the carrier has fixed
     *    it, proves nothing.
     *  - it is kept, so "this is what the path looked like at 15:34 when it started"
     *    is a record and not a memory.
     */
    public function up(): void
    {
        Schema::create('circuit_path_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->string('target', 45);
            // 'auto' when the poller captured it on a loss crossing; 'manual' from the UI.
            $table->string('trigger', 16)->default('manual');
            $table->string('tool', 16);                   // mtr | traceroute
            $table->unsignedSmallInteger('cycles')->default(0);
            // [{hop, host, ip, sent, loss_pct, avg_ms, best_ms, worst_ms}, ...]
            $table->json('hops');
            // The first hop showing loss that PERSISTS to the end of the path. A hop that
            // drops ICMP itself while every later hop is clean is rate-limiting, not
            // losing traffic — see CircuitPathProbe::worstHop().
            $table->unsignedTinyInteger('worst_hop')->nullable();
            $table->string('worst_hop_host')->nullable();
            $table->unsignedTinyInteger('worst_hop_loss_pct')->nullable();
            $table->unsignedTinyInteger('end_loss_pct')->nullable();
            $table->string('summary')->nullable();
            $table->string('ran_by')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['circuit_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_path_probes');
    }
};
