<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the last poll actually produced a reading.
     *
     * A ping that never completes is not a measurement of anything, and the app used to
     * record it as 100% loss. On 15 September queued path probes loaded the collector,
     * pings started exceeding their timeout, and 232 of 255 circuits reported down at
     * once — every carrier, while all 304 devices stayed reachable and no appliance
     * raised a WAN alarm.
     *
     * The poller now declines to grade an unmeasured circuit. This flag records that,
     * so the UI can say "not measured this cycle" instead of showing a stale reading as
     * if it were current — unmeasured must read as neither healthy nor broken.
     */
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->boolean('last_measured_ok')->default(true)->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('last_measured_ok');
        });
    }
};
