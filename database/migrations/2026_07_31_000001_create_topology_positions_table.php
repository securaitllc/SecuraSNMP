<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-saved node positions for a site's topology map. LLDP can't always resolve
 * the true uplink (a small access switch with LLDP disabled), so an admin can drag the
 * nodes into the real layout and save it — the positions are global (one arrangement
 * per site) so every user sees the corrected map. A node with no saved position falls
 * back to the auto-computed tier layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topology_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('node_id');   // "sw-7", "ec-8", "fw-13", "isp-6", "nh-3"…
            $table->float('x');
            $table->float('y');
            $table->timestamps();

            $table->unique(['site_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topology_positions');
    }
};
