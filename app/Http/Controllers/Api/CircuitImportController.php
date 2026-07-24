<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-imports circuits from a pasted "site  ip  interface" list so a NOC can
 * START monitoring a WAN link immediately — before the ISP details (CID, carrier,
 * provider) are known. Those are filled in later on the circuit itself. The
 * circuit id and ISP name are placeholders ("PENDING-wan0", "Pending") until then.
 *
 * A private 192.168.x.x monitored IP is flagged as DHCP (its public IP sits behind
 * ISP NAT and can't be pinged directly); the operator can refine it afterwards.
 */
class CircuitImportController extends Controller
{
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'circuits' => ['required', 'array', 'min:1'],
            'circuits.*.site' => ['required', 'string', 'max:255'],
            'circuits.*.ip' => ['required', 'ip'],
            'circuits.*.interface' => ['required', 'string', 'max:16'],
            'dry_run' => ['boolean'],
        ]);
        $dryRun = (bool) $request->boolean('dry_run');

        $siteByNumber = Site::whereNotNull('site_number')->get()->keyBy(fn ($s) => $this->pad($s->site_number));
        // Already-present circuits, deduped on (site_id, monitored_ip).
        $have = Circuit::select('site_id', 'monitored_ip')->get()
            ->map(fn ($c) => $c->site_id.'|'.strtolower((string) $c->monitored_ip))->flip();

        $created = [];
        $skipped = [];
        $unmatched = [];
        $seen = [];

        DB::transaction(function () use ($data, $dryRun, $siteByNumber, &$have, &$created, &$skipped, &$unmatched, &$seen) {
            foreach ($data['circuits'] as $row) {
                $ip = trim($row['ip']);
                $iface = strtolower(trim($row['interface']));
                $sc = $this->siteNumber($row['site']);
                $site = $sc !== null ? ($siteByNumber[$sc] ?? null) : null;
                $label = trim($row['site']).' '.$ip.' '.$iface;

                if (! $site) {
                    $unmatched[] = $label;

                    continue;
                }

                $key = $site->id.'|'.strtolower($ip);
                if (isset($seen[$key]) || isset($have[$key])) {
                    $skipped[] = $label;

                    continue;
                }
                $seen[$key] = true;

                if (! $dryRun) {
                    Circuit::create([
                        'site_id' => $site->id,
                        // Placeholders until the ISP details arrive — editable on the circuit.
                        'circuit_id' => 'PENDING-'.$iface,
                        'isp_name' => 'Pending',
                        'circuit_type' => 'fiber',
                        'monitored_ip' => $ip,
                        'wan_interface' => $iface,
                        // 192.168.x.x public IP = DHCP behind ISP NAT; flag it so the
                        // operator treats it differently (refine later).
                        'ip_assignment' => str_starts_with($ip, '192.168.') ? 'dhcp' : 'static',
                        'monitor_via' => 'icmp',
                        'ping_target' => '8.8.8.8',
                        'status' => 'up',
                        'monitoring_enabled' => true,
                        'notes' => 'Bulk-imported — awaiting CID / carrier / ISP provider.',
                    ]);
                    $have[$key] = true;
                }
                $created[] = $label;
            }
        });

        sort($created);
        sort($skipped);
        sort($unmatched);

        return response()->json([
            'dry_run' => $dryRun,
            'created_count' => count($created),
            'created' => $created,
            'skipped_existing_count' => count($skipped),
            'skipped_existing' => $skipped,
            'unmatched_site_count' => count($unmatched),
            'unmatched_site' => $unmatched,
        ]);
    }

    /** The service-center number embedded in a site name (…SC208… → "208"). */
    private function siteNumber(string $name): ?string
    {
        return preg_match('/SC0*(\d{1,4})/i', $name, $m) ? $this->pad($m[1]) : null;
    }

    /** Zero-pad a service-center number to 3 digits (matches Site Import). */
    private function pad(string $n): string
    {
        return str_pad(preg_replace('/\D/', '', $n) ?: '0', 3, '0', STR_PAD_LEFT);
    }
}
