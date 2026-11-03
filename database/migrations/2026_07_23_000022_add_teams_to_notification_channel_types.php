<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow 'teams' (Microsoft Teams incoming webhook) as a notification-channel
 * type. The column was a DB enum, which both MySQL and SQLite enforce (SQLite
 * via a CHECK constraint) — adding a value would need a fragile per-driver ALTER.
 * Instead relax it to a plain string; the allowed set is validated in
 * NotificationChannelRequest ('in:email,slack,webhook,teams'), the single source
 * of truth. This also avoids the recurring SQLite-vs-MySQL enum divergence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->string('type')->change();
        });
    }

    public function down(): void
    {
        // Intentionally left as a no-op: narrowing back to an enum would reject
        // any 'teams' rows already stored. The column stays a string.
    }
};
