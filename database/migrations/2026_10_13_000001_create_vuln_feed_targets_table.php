<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per CVE-feed target (a vendor/product CPE we query), holding the
        // outcome of the most recent pull.
        //
        // This table is the drift guard. The feed used to report only a fleet-wide
        // "N CVEs upserted" line, so a single target that had gone stale and was
        // returning zero results was invisible inside a healthy-looking total. It
        // stayed invisible for the whole life of the feature. Recording the result per
        // target makes an empty answer something the platform can show somebody.
        Schema::create('vuln_feed_targets', function (Blueprint $table) {
            $table->id();
            $table->string('cpe')->unique();              // cpe:2.3:o:hpe:edgeconnect_operating_system
            $table->string('vendor');                      // juniper/silverpeak/fortigate
            $table->string('product')->nullable();          // junos/edgeconnect/orchestrator
            // ok / empty / no_ranges / error — plain string, never a DB enum: these
            // values come from feed behaviour and SQLite would accept a new one while
            // MySQL rejected it. Same rule as the device and alarm columns.
            $table->string('status')->default('ok');
            $table->unsignedInteger('cve_count')->default(0);      // CVEs with a usable range
            $table->unsignedInteger('total_results')->default(0);  // CVEs NVD has for the CPE
            $table->text('message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vuln_feed_targets');
    }
};
