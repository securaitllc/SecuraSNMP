<?php

use App\Models\InterfaceAlert;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Dedupe: a logical sub-unit (ge-0/0/11.0) inherited its physical parent's
        // down state and raised a duplicate alarm. Close any such open alert; the
        // poller no longer opens them.
        InterfaceAlert::whereNull('ended_at')->with('deviceInterface')->get()
            ->filter(fn ($a) => preg_match('/\.\d+$/', (string) optional($a->deviceInterface)->if_name))
            ->each(fn ($a) => $a->updateQuietly(['ended_at' => now(), 'cleared_manually' => true]));
    }

    public function down(): void
    {
        // No-op.
    }
};
