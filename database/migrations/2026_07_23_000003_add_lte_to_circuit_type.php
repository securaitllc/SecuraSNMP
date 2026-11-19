<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add LTE as a circuit transport. MySQL enforces the enum, so widen it;
        // SQLite (tests) stores enums as free text, so nothing to alter there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE circuits MODIFY circuit_type ENUM('fiber', 'cable', 'lte') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE circuits MODIFY circuit_type ENUM('fiber', 'cable') NOT NULL");
        }
    }
};
