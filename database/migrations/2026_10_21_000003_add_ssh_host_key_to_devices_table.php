<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SSH host key pinned on first contact. A later connection that presents
     * a different key is refused before any credential is sent, so a repointed
     * IP or an on-path box cannot harvest the stored SSH password.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('ssh_host_key')->nullable()->after('ssh_credential_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('ssh_host_key');
        });
    }
};
