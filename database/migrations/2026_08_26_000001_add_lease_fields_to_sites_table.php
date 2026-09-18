<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether Massey owns or leases each location, and when a lease runs out.
 *
 * This is contract-decision data: an ISP contract signed past the lease end is a
 * liability if the site moves, so the lease date is what makes "renew / extend /
 * let it lapse" answerable. Owned sites simply carry a null lease_end_date —
 * there is nothing to track and nothing to warn about.
 *
 * occupancy is a plain string, NOT a DB enum: SQLite (dev/tests) ignores enum
 * constraints while MySQL (prod) rejects unknown values, so enums pass locally
 * and 500 in production. Validated with `in:` at the request layer instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('occupancy', 16)->default('leased')->after('address');
            $table->date('lease_end_date')->nullable()->after('occupancy');
            $table->text('lease_notes')->nullable()->after('lease_end_date');
            $table->index('lease_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['lease_end_date']);
            $table->dropColumn(['occupancy', 'lease_end_date', 'lease_notes']);
        });
    }
};
