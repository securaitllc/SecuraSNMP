<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce the 'analyst' role (read + alarm ack/clear/dispatch, but no config
 * changes). `role` was a DB enum('admin','viewer'); both MySQL and SQLite enforce
 * it, so adding a value needs a fragile per-driver ALTER. Relax it to a plain
 * string — the allowed set (admin|analyst|viewer) is validated in UserRequest,
 * the single source of truth — matching how the notification-channel type was
 * handled and sidestepping the SQLite-vs-MySQL enum divergence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('viewer')->change();
        });
    }

    public function down(): void
    {
        // No-op: narrowing back to an enum would reject any 'analyst' rows.
    }
};
