<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an acknowledgement should be reconsidered.
     *
     * Acknowledging a finding is currently permanent. That is how a backlog dies: on
     * 6 October production carried 133 open findings — 37 critical, 28 high — and the
     * only disposition available was "acknowledged, forever". A decision taken in
     * October about a CVE nobody could patch that week is not a decision that should
     * still be holding in March, silently, with nothing to prompt a second look.
     *
     * Nullable on purpose. An acknowledgement with no review date is a permanent
     * accept-the-risk, which is a legitimate answer; one WITH a date comes back by
     * itself. The state is never rewritten behind the operator's back — a finding
     * past its review date stays `acknowledged` and is reported as due, because
     * silently reopening somebody's decision is its own kind of lie.
     */
    public function up(): void
    {
        Schema::table('device_vulnerabilities', function (Blueprint $table) {
            $table->date('review_at')->nullable()->after('ack_note');
            $table->index('review_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_vulnerabilities', function (Blueprint $table) {
            $table->dropIndex(['review_at']);
            $table->dropColumn('review_at');
        });
    }
};
