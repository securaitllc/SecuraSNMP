<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NetFlow / sFlow flow records + rollups.
 *
 * Raw flows (every sampled conversation) are kept short — they're high-volume — and
 * rolled up into hourly/daily aggregates for the long view, so the flow DB stays
 * bounded (the SNMP-history bloat lesson applied up front). Retention is enforced by
 * `flows:rollup`:  raw 48h · hourly rollups 30d · daily rollups 13 months.
 *
 * Enum-free strings for anything network-sourced (protocol, direction, app) per the
 * SQLite(dev)/MySQL(prod) divergence rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_records', function (Blueprint $table) {
            $table->id();
            // The exporter that sampled the flow (matched from the sampler/agent IP).
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('if_index')->nullable();     // ingress interface
            $table->string('src_ip', 45);
            $table->string('dst_ip', 45);
            $table->unsignedInteger('src_port')->nullable();
            $table->unsignedInteger('dst_port')->nullable();
            $table->string('protocol', 12)->nullable();          // tcp/udp/icmp/esp/gre
            $table->string('app')->nullable();                   // classified: "Microsoft 365"
            $table->string('app_category')->nullable();          // "SaaS" / "Voice" / …
            $table->string('direction', 12)->nullable();         // inbound/outbound/east-west
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('packets')->default(0);
            $table->timestamp('flow_start')->nullable();
            $table->timestamp('recorded_at')->index();

            // Search paths: by exporter+time (the device Flows tab), and by endpoint/app.
            $table->index(['device_id', 'recorded_at']);
            $table->index(['src_ip', 'recorded_at']);
            $table->index(['dst_ip', 'recorded_at']);
            $table->index(['app', 'recorded_at']);
        });

        Schema::create('flow_rollups', function (Blueprint $table) {
            $table->id();
            // Non-null unique-key columns: MySQL treats NULL as distinct in a UNIQUE
            // index, which would defeat the rollup upsert. Rollups only ever cover
            // matched flows, so device_id is always set; if_index/sub_key default to a
            // sentinel ('' / 0) rather than null. No FK — a rollup outlives device edits.
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('if_index')->default(0);
            $table->string('bucket', 8);                         // 'hour' | 'day'
            $table->timestamp('bucket_start');
            // group_type = 'talker' (group_key=src_ip, sub_key=dst_ip) or 'app' (group_key=app).
            $table->string('group_type', 12);
            $table->string('group_key');
            $table->string('sub_key')->default('');
            $table->string('app_category')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('packets')->default(0);
            $table->unsignedBigInteger('flows')->default(0);

            $table->unique(['device_id', 'if_index', 'bucket', 'bucket_start', 'group_type', 'group_key', 'sub_key'], 'flow_rollup_unique');
            $table->index(['device_id', 'bucket', 'bucket_start']);
            $table->index(['bucket', 'bucket_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_rollups');
        Schema::dropIfExists('flow_records');
    }
};
