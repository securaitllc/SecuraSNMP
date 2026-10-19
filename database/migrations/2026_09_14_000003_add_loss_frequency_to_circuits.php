<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How OFTEN a circuit loses packets, not just how much it loses when it does.
     *
     * sustained_loss_pct is the median of the last five polls. That was chosen so one
     * dropped probe could not read as degraded, and for a circuit that is either fine
     * or broken it works. It cannot describe a circuit that is intermittently both.
     *
     * #037 Lawrenceville's DIA, 445453113, over one day: 206 polls, 96 of them lossy —
     * 47% — with peaks of 90, 70, 60 and 50 percent, all day, continuously. Fewer than
     * three polls in any five are lossy, so the median is 0. The app reported the
     * circuit UP with sustained loss 0 and raised nothing, while nearly half of every
     * probe set was losing packets.
     *
     * A median is the wrong summary for a bimodal signal. It answers "how bad is this
     * usually" and a flapping circuit has no usually. Frequency answers the question
     * the median cannot, and the peak says how bad it gets when it goes.
     *
     * Both are recorded over a longer window than the median so a genuinely occasional
     * drop still reads as occasional.
     */
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            // Share of the recent polls that lost ANY packets, 0-100.
            $table->unsignedTinyInteger('loss_polls_pct')->default(0)->after('sustained_loss_pct');
            // The worst single poll in that window — how bad it gets when it goes.
            $table->unsignedTinyInteger('loss_peak_pct')->default(0)->after('loss_polls_pct');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn(['loss_polls_pct', 'loss_peak_pct']);
        });
    }
};
