<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop stored device configurations.
     *
     * The config-backup feature is removed: a running-config is the most sensitive
     * thing an appliance holds, and keeping copies of it in this database is a risk
     * the product does not need to carry. Secrets were redacted before storage, but
     * the rest — addressing, routing, tunnel and ACL detail — is still a map of the
     * customer's network.
     *
     * Dropping the table is the purge: it removes every captured config along with
     * the schema, in one step, on deploy.
     */
    public function up(): void
    {
        Schema::dropIfExists('device_configs');
    }

    public function down(): void
    {
        // Not reversed. Recreating an empty table would restore nothing and reinstate
        // storage for data this change exists to stop keeping.
    }
};
