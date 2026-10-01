<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // When set, the device uses this shared SSH credential instead of
            // its inline ssh_username/ssh_credential.
            $table->foreignId('ssh_credential_id')->nullable()->after('ssh_credential')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ssh_credential_id');
        });
    }
};
