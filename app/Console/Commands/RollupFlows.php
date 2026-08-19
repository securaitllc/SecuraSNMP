<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\FlowRecord;
use App\Models\FlowRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rolls raw flows up into hourly then daily aggregates and enforces retention.
 *
 * Raw flows are high-volume and kept only 48h; the long view lives in rollups. Each
 * pass (idempotent — upserts on a stable key) folds completed hours into hourly
 * rollups (per talker AND per app), completed days into daily rollups, then prunes
 * anything past its window:  raw 48h · hourly 30d · daily 13 months.
 */
class RollupFlows extends Command
{
    use RunsPollLoop;

    protected $signature = 'flows:rollup {--once : Run a single pass and exit}';

    protected $description = 'Aggregate raw flows into hourly/daily rollups and enforce flow retention.';

    public function handle(): int
    {
        if ($this->option('once')) {
            $this->pass();

            return self::SUCCESS;
        }

        $this->info('Flow rollup started (every 10 min).');
        $this->pollForever('flow-rollup', 600, fn () => $this->pass());

        return self::SUCCESS;
    }

    public function pass(): void
    {
        $now = CarbonImmutable::now();
        // Fold the last few COMPLETED hours (idempotent — a re-run just re-upserts).
        for ($h = 1; $h <= 3; $h++) {
            $this->rollupHour($now->subHours($h)->startOfHour());
        }
        // Fold yesterday into a daily rollup.
        $this->rollupDay($now->subDay()->startOfDay());
        $this->prune($now);
    }

    private function rollupHour(CarbonImmutable $start): void
    {
        $end = $start->addHour();

        // Per-talker (src → dst) and per-app aggregates for the window.
        $talkers = FlowRecord::whereNotNull('device_id')
            ->whereBetween('recorded_at', [$start, $end])
            ->selectRaw('device_id, if_index, src_ip, dst_ip, SUM(bytes) as bytes, SUM(packets) as packets, COUNT(*) as flows')
            ->groupBy('device_id', 'if_index', 'src_ip', 'dst_ip')->get();

        foreach ($talkers as $r) {
            $this->upsert('hour', $start, $r, 'talker', (string) $r->src_ip, (string) $r->dst_ip);
        }

        $apps = FlowRecord::whereNotNull('device_id')
            ->whereBetween('recorded_at', [$start, $end])
            ->selectRaw('device_id, if_index, app, MAX(app_category) as app_category, SUM(bytes) as bytes, SUM(packets) as packets, COUNT(*) as flows')
            ->groupBy('device_id', 'if_index', 'app')->get();

        foreach ($apps as $r) {
            $this->upsert('hour', $start, $r, 'app', (string) $r->app, '', $r->app_category);
        }
    }

    private function rollupDay(CarbonImmutable $start): void
    {
        $end = $start->addDay();

        $rows = FlowRollup::where('bucket', 'hour')
            ->whereBetween('bucket_start', [$start, $end])
            ->selectRaw('device_id, if_index, group_type, group_key, sub_key, MAX(app_category) as app_category, SUM(bytes) as bytes, SUM(packets) as packets, SUM(flows) as flows')
            ->groupBy('device_id', 'if_index', 'group_type', 'group_key', 'sub_key')->get();

        foreach ($rows as $r) {
            FlowRollup::updateOrCreate(
                ['device_id' => $r->device_id, 'if_index' => (int) $r->if_index, 'bucket' => 'day',
                    'bucket_start' => $start, 'group_type' => $r->group_type, 'group_key' => $r->group_key, 'sub_key' => (string) $r->sub_key],
                ['app_category' => $r->app_category, 'bytes' => (int) $r->bytes, 'packets' => (int) $r->packets, 'flows' => (int) $r->flows],
            );
        }
    }

    private function upsert(string $bucket, CarbonImmutable $start, object $r, string $type, string $key, string $sub, ?string $cat = null): void
    {
        FlowRollup::updateOrCreate(
            ['device_id' => $r->device_id, 'if_index' => (int) ($r->if_index ?? 0), 'bucket' => $bucket,
                'bucket_start' => $start, 'group_type' => $type, 'group_key' => $key, 'sub_key' => $sub],
            ['app_category' => $cat, 'bytes' => (int) $r->bytes, 'packets' => (int) $r->packets, 'flows' => (int) $r->flows],
        );
    }

    private function prune(CarbonImmutable $now): void
    {
        FlowRecord::where('recorded_at', '<', $now->subHours((int) env('FLOW_RAW_HOURS', 48)))->delete();
        FlowRollup::where('bucket', 'hour')->where('bucket_start', '<', $now->subDays((int) env('FLOW_HOURLY_DAYS', 30)))->delete();
        FlowRollup::where('bucket', 'day')->where('bucket_start', '<', $now->subDays((int) env('FLOW_DAILY_DAYS', 395)))->delete();
    }
}
