<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A circuit can serve MORE than one site (e.g. a CORP LAB's internet shared
    // with another site). circuits.site_id stays the owner; this pivot holds the
    // additional sites it feeds, so its outage impacts all of them.
    public function up(): void
    {
        Schema::create('circuit_site', function (Blueprint $table) {
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->primary(['circuit_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_site');
    }
};
