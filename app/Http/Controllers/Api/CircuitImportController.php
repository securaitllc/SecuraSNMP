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

        // A row whose site number matches nothing is PARKED under a holding site
        // rather than dropped, so a NOC can start monitoring now and drag it to the
        // right site later (the circuit edit form has a site picker). Found only if
        // it already exists; created lazily on the first real park (not in dry-run).
        $holding = Site::firstWhere('name', self::HOLDING_SITE_NAME);

        $created = [];
        $parked = [];
        $skipped = [];
        $seen = [];

        DB::transaction(function () use ($data, $dryRun, $siteByNumber, &$have, &$created, &$parked, &$skipped, &$seen, &$holding) {
            foreach ($data['circuits'] as $row) {
                $ip = trim($row['ip']);
                $iface = strtolower(trim($row['interface']));
                $sc = $this->siteNumber($row['site']);
                $site = $sc !== null ? ($siteByNumber[$sc] ?? null) : null;
                $label = trim($row['site']).' '.$ip.' '.$iface;
                $isParked = $site === null;

                if ($isParked && ! $dryRun) {
                    $holding ??= Site::create([
                        'name' => self::HOLDING_SITE_NAME,
                        'site_type' => 'branch',
                        'notes' => 'Holding site for bulk-imported circuits whose site could not be matched. Reassign each to its real site.',
                    ]);
                }
                $targetId = $isParked ? $holding?->id : $site->id;

                // Dedup on (target site, IP). A parked row in dry-run has no holding
                // id yet — key it as PARK so repeated unmatched IPs still collapse.
                $key = ($targetId ?? 'PARK').'|'.strtolower($ip);
                if (isset($seen[$key]) || isset($have[$key])) {
                    $skipped[] = $label;

                    continue;
                }
                $seen[$key] = true;

                if (! $dryRun) {
                    $this->makeCircuit($targetId, $ip, $iface, $isParked);
                    $have[$key] = true;
                }
                if ($isParked) {
                    $parked[] = $label;
                } else {
                    $created[] = $label;
                }
            }
        });

        sort($created);
        sort($parked);
        sort($skipped);

        return response()->json([
            'dry_run' => $dryRun,
            'created_count' => count($created),
            'created' => $created,
            'parked_count' => count($parked),
            'parked' => $parked,
            'parked_site' => $holding ? ['id' => $holding->id, 'name' => $holding->name] : ['name' => self::HOLDING_SITE_NAME],
            'skipped_existing_count' => count($skipped),
            'skipped_existing' => $skipped,
        ]);
    }

    /** Name of the holding site unmatched imports are parked under. */
    private const HOLDING_SITE_NAME = 'Unassigned Imports';

    /** Create a monitoring-enabled circuit with placeholder ISP details. */
    private function makeCircuit(int $siteId, string $ip, string $iface, bool $parked): void
    {
        Circuit::create([
            'site_id' => $siteId,
            // Placeholders until the ISP details arrive — editable on the circuit.
            'circuit_id' => 'PENDING-'.$iface,
            'isp_name' => 'Pending',
            'circuit_type' => 'fiber',
            'monitored_ip' => $ip,
            'wan_interface' => $iface,
            // 192.168.x.x public IP = DHCP behind ISP NAT; flag it so the operator
            // treats it differently (refine later).
            'ip_assignment' => str_starts_with($ip, '192.168.') ? 'dhcp' : 'static',
            'monitor_via' => 'icmp',
            'ping_target' => '8.8.8.8',
            'status' => 'up',
            'monitoring_enabled' => true,
            'notes' => $parked
                ? 'Bulk-imported — site UNMATCHED, parked here. Reassign to the correct site, then fill CID / carrier / ISP.'
                : 'Bulk-imported — awaiting CID / carrier / ISP provider.',
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
