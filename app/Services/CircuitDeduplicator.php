<?php

namespace App\Services;

use App\Models\Circuit;
use Illuminate\Support\Facades\DB;

/**
 * Collapses TRUE duplicate circuits — the same monitored IP entered twice for
 * the same site — down to one row. Bulk imports and hand entry both produce
 * these; two rows pinging the same IP double every alarm.
 *
 * The keeper is the most-complete row (a real CID beats a "PENDING-…"
 * placeholder, more filled fields beats fewer). The losers' alerts and metric
 * history are repointed to the keeper first, so no monitoring history is lost,
 * then the loser rows are deleted. plan() previews; apply() performs in a
 * transaction. Dedup key is (site_id, lower(trim(monitored_ip))) — a blank IP is
 * never deduped (nothing to match on).
 */
class CircuitDeduplicator
{
    /** @return list<array{site_id:int, monitored_ip:string, keep:int, keep_cid:?string, delete:list<int>, delete_cids:list<?string>}> */
    public function plan(): array
    {
        $groups = [];
        Circuit::whereNotNull('monitored_ip')->where('monitored_ip', '!=', '')->get()
            ->groupBy(fn (Circuit $c) => $c->site_id.'|'.strtolower(trim((string) $c->monitored_ip)))
            ->each(function ($dupes, $key) use (&$groups) {
                if ($dupes->count() < 2) {
                    return;
                }
                $keeper = $dupes->sortByDesc(fn (Circuit $c) => $this->score($c))->first();
                $losers = $dupes->reject(fn (Circuit $c) => $c->id === $keeper->id)->values();
                [$siteId, $ip] = explode('|', $key, 2);
                $groups[] = [
                    'site_id' => (int) $siteId,
                    'monitored_ip' => $ip,
                    'keep' => $keeper->id,
                    'keep_cid' => $keeper->circuit_id,
                    'delete' => $losers->pluck('id')->all(),
                    'delete_cids' => $losers->pluck('circuit_id')->all(),
                ];
            });

        return $groups;
    }

    /** @return array{groups:int, deleted:int, plan:array} */
    public function apply(): array
    {
        $plan = $this->plan();
        $deleted = 0;

        DB::transaction(function () use ($plan, &$deleted) {
            foreach ($plan as $group) {
                $keep = $group['keep'];
                foreach ($group['delete'] as $loserId) {
                    // Preserve history: move the loser's alerts + metrics to the keeper.
                    DB::table('circuit_alerts')->where('circuit_id', $loserId)->update(['circuit_id' => $keep]);
                    DB::table('circuit_metric_history')->where('circuit_id', $loserId)->update(['circuit_id' => $keep]);
                    // Shared-site links: repoint, dropping any that would collide with
                    // a link the keeper already has (composite-unique pivot).
                    foreach (DB::table('circuit_site')->where('circuit_id', $loserId)->get() as $link) {
                        $exists = DB::table('circuit_site')
                            ->where('circuit_id', $keep)->where('site_id', $link->site_id)->exists();
                        DB::table('circuit_site')->where('circuit_id', $loserId)->where('site_id', $link->site_id)
                            ->{$exists ? 'delete' : 'update'}($exists ? [] : ['circuit_id' => $keep]);
                    }
                    Circuit::whereKey($loserId)->delete();
                    $deleted++;
                }
            }
        });

        return ['groups' => count($plan), 'deleted' => $deleted, 'plan' => $plan];
    }

    /**
     * Completeness rank for keeper selection. Higher wins. A real CID dominates a
     * "PENDING-…" placeholder; then a named ISP; then filled fields; oldest row
     * (lowest id) breaks ties so the result is deterministic.
     */
    private function score(Circuit $c): string
    {
        $realCid = $c->circuit_id && ! str_starts_with((string) $c->circuit_id, 'PENDING') ? 1 : 0;
        $realIsp = $c->isp_name && strcasecmp((string) $c->isp_name, 'Pending') !== 0 ? 1 : 0;
        $filled = count(array_filter([
            $c->isp_provider_id, $c->gateway_ip, $c->account_number, $c->support_phone,
            $c->wan_interface, $c->subnet, $c->lec_name, $c->circuit_type, $c->account_number,
        ]));

        // Zero-padded sortable string; invert id so a LOWER id ranks higher on ties.
        return sprintf('%d%d%02d%010d', $realCid, $realIsp, $filled, 9999999999 - $c->id);
    }
}
