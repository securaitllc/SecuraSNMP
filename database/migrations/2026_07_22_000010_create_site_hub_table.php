<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A branch tunnels to MORE than one hub (Massey has 2). Many-to-many: a
    // branch homes to N hubs. The legacy single sites.hub_site_id is migrated in
    // and kept as a fallback; the pivot is the source of truth going forward.
    public function up(): void
    {
        Schema::create('site_hub', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();          // the branch
            $table->foreignId('hub_site_id')->constrained('sites')->cascadeOnDelete(); // the hub
            $table->primary(['site_id', 'hub_site_id']);
        });

        // Carry existing single-hub assignments into the pivot.
        foreach (DB::table('sites')->whereNotNull('hub_site_id')->get(['id', 'hub_site_id']) as $s) {
            DB::table('site_hub')->insertOrIgnore(['site_id' => $s->id, 'hub_site_id' => $s->hub_site_id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_hub');
    }
};
