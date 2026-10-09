<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loss measured on the circuit's actual traffic, by the appliance carrying it.
 *
 * Every circuit's loss figure came from pinging the carrier's own gateway. Routers
 * police ICMP addressed to themselves, so that number was measuring a rate limiter,
 * not a circuit: 300 packets to Lumen's provider edge at #037 lost 8%, while 300 to
 * our own appliance one hop FURTHER — through that same gateway — lost none. The
 * underlay tunnel on that circuit had passed 574,089 packets in 23 hours with zero
 * lost. A week of investigation, and three rounds with the carrier, chased a policer.
 *
 * The appliance already counts what actually crosses the wire and `show tunnel`
 * already reports it. These columns hold the last cumulative reading so a rate can
 * be derived from the delta, plus the loss percentage over the last interval.
 *
 * Nullable on purpose: a standby tunnel passes no traffic, and no traffic is not
 * evidence of health. Null means unmeasured and must never render as clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            // Loss over the last interval, from the appliance's own counters.
            $table->float('transport_loss_pct')->nullable()->after('loss_peak_pct');
            // Last cumulative readings, so the next poll can take a delta.
            $table->unsignedBigInteger('transport_rx_pkts')->nullable()->after('transport_loss_pct');
            $table->unsignedBigInteger('transport_lost_pkts')->nullable()->after('transport_rx_pkts');
            // Packets that actually moved in the last interval — the sample size the
            // percentage rests on. Four packets losing one is not 25% loss.
            $table->unsignedBigInteger('transport_sample_pkts')->nullable()->after('transport_lost_pkts');
            $table->timestamp('transport_measured_at')->nullable()->after('transport_sample_pkts');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn([
                'transport_loss_pct', 'transport_rx_pkts', 'transport_lost_pkts',
                'transport_sample_pkts', 'transport_measured_at',
            ]);
        });
    }
};
