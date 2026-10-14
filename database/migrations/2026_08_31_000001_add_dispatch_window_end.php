<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dispatch is a WINDOW, not an instant.
 *
 * ISPs almost never commit to a time — they commit to an arrival window ("tomorrow
 * 08:00–12:00"), and often give a window with no ticket number at all. The schema only
 * held a single `dispatch_at`, so the NOC had to flatten a four-hour window down to one
 * timestamp and lose the commitment they would later hold the ISP to.
 *
 * `dispatch_at` keeps its meaning — the START of the window (and still the whole answer
 * when an ISP does give a single ETA). `dispatch_end_at` is the end, nullable, so every
 * existing row stays valid and reads as a point-in-time ETA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            if (! Schema::hasColumn('circuits', 'dispatch_end_at')) {
                $table->timestamp('dispatch_end_at')->nullable()->after('dispatch_at');
            }
        });

        Schema::table('circuit_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('circuit_alerts', 'dispatch_end_at')) {
                $table->timestamp('dispatch_end_at')->nullable()->after('dispatch_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('circuits', fn (Blueprint $t) => $t->dropColumn('dispatch_end_at'));
        Schema::table('circuit_alerts', fn (Blueprint $t) => $t->dropColumn('dispatch_end_at'));
    }
};
