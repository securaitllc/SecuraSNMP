<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the account last proved itself with credentials.
 *
 * A Laravel session lifetime is INACTIVITY, not age: every request slides it
 * forward, so a NOC tab polling every 30s never expires. Remember-me then hides what
 * is left, because an expired session is silently re-authenticated from the cookie
 * without anyone typing a password. Between them, "sessions expire after N hours"
 * was never true for the people actually using the app.
 *
 * Absolute age has to live somewhere a new session cannot reset, so it lives on the
 * user. Only a real credential login writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        // Existing sessions have no recorded login. Stamp them now rather than
        // leaving null, so deploying this does not sign the whole NOC out mid-shift;
        // they age out normally from here.
        \Illuminate\Support\Facades\DB::table('users')->whereNull('last_login_at')->update(['last_login_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
