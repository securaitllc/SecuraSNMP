<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk-imports network devices (a switch fleet, an SD-WAN fleet) from a pasted
 * list of "name (ip)" lines. The name carries the service-center number
 * (…SC208…) which maps the device to an already-imported site; the shared
 * settings (role, vendor, SNMP community, SSH credential) come from the import
 * form so no site-specific secret ever lives in code. Safeguards:
 *   - A device already present (same IP or same name) is skipped, never dupliated.
 *   - A line whose SC number matches no site is reported as unmatched, not created.
 * Pass dry_run to preview the create / skip / unmatched decisions without writing.
 */
class DeviceImportController extends Controller
{
    public function import(Request $request): JsonResponse
    {
        // Normalise the SNMP version to the DB enum ('v2c'/'v3') before validating —
        // the rest of the app stores 'v2c'/'v3', and a bare '2c'/'3' would be
        // rejected by the real DB's enum (SQLite silently accepts it, which hid
        // this in tests). Map the common bare forms so old input still works.
        if ($request->filled('snmp_version')) {
            $request->merge(['snmp_version' => ['2c' => 'v2c', '3' => 'v3'][$request->input('snmp_version')] ?? $request->input('snmp_version')]);
        }

        // Validate role/vendor/snmp_version against the ACTUAL column enums so an
        // out-of-range value returns a clear 422 instead of a 500 from the DB.
        $data = $request->validate([
            'devices' => ['required', 'array', 'min:1'],
            'devices.*.name' => ['required', 'string', 'max:255'],
            'devices.*.ip' => ['required', 'ip'],
            'role' => ['required', 'in:switch,edgeconnect,firewall'],
            'vendor' => ['required', 'in:juniper,silverpeak,fortigate'],
            'snmp_version' => ['nullable', 'in:v2c,v3'],
            'snmp_community' => ['nullable', 'string', 'max:255'],
            'ssh_credential_id' => ['nullable', 'integer', 'exists:ssh_credentials,id'],
            'model' => ['nullable', 'string', 'max:255'],
            // Park devices whose SC number matches no site in a "General" holding
            // site (reassign later) instead of skipping them.
            'fallback_general' => ['boolean'],
            'dry_run' => ['boolean'],
        ]);
        $dryRun = (bool) $request->boolean('dry_run');
        $fallback = (bool) $request->boolean('fallback_general');

        // Site lookup by 3-digit service-center number (matches Site Import's key).
        $siteByNumber = Site::whereNotNull('site_number')->get()->keyBy(fn ($s) => $this->pad($s->site_number));

        // Already-present devices — dedupe on IP and on name.
        $haveIp = Device::pluck('ip_address')->filter()->map(fn ($ip) => strtolower($ip))->flip();
        $haveName = Device::pluck('name')->filter()->map(fn ($n) => strtolower($n))->flip();

        $created = [];
        $skipped = [];
        $unmatched = [];
        $general = [];   // parked in the General holding site
        $seen = [];      // guard against duplicate lines within one paste

        // All-or-nothing: if any create throws, the whole import rolls back so a
        // half-imported fleet never lands (a dry run does no writes, so this is a
        // no-op preview).
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $dryRun, $fallback, $siteByNumber, &$haveIp, &$haveName, &$created, &$skipped, &$unmatched, &$general, &$seen) {
        // Resolve the General holding site once (only when actually needed + writing).
        $generalSite = null;
        foreach ($data['devices'] as $d) {
            $name = trim($d['name']);
            $ip = trim($d['ip']);
            $key = strtolower($name).'|'.strtolower($ip);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (isset($haveIp[strtolower($ip)]) || isset($haveName[strtolower($name)])) {
                $skipped[] = $name;

                continue;
            }

            $sc = $this->siteNumber($name);
            $site = $sc !== null ? ($siteByNumber[$sc] ?? null) : null;
            $toGeneral = false;
            if (! $site) {
                if (! $fallback) {
                    $unmatched[] = $name;

                    continue;
                }
                // Park it in the General holding site (created once, on demand).
                if (! $dryRun) {
                    $generalSite ??= Site::firstOrCreate(
                        ['name' => 'General (unassigned)'],
                        ['site_type' => 'branch'],
                    );
                    $site = $generalSite;
                }
                $toGeneral = true;
            }

            if (! $dryRun) {
                Device::create([
                    'site_id' => $site->id,
                    'name' => $name,
                    'ip_address' => $ip,
                    'role' => $data['role'],
                    'vendor' => $data['vendor'],
                    'model' => $data['model'] ?? 'Unknown',
                    'snmp_version' => $data['snmp_version'] ?? 'v2c',
                    'snmp_community' => $data['snmp_community'] ?? null,
                    'ssh_credential_id' => $data['ssh_credential_id'] ?? null,
                    // status is enum(active|inactive); imported devices are monitored.
                    'status' => 'active',
                ]);
                // Reserve the IP/name so a repeated line in the same paste can't dupe.
                $haveIp[strtolower($ip)] = true;
                $haveName[strtolower($name)] = true;
            }
            if ($toGeneral) {
                $general[] = $name;
            }
            else {
                $created[] = $name;
            }

        }
        });

        sort($created);
        sort($skipped);
        sort($unmatched);
        sort($general);

        return response()->json([
            'dry_run' => $dryRun,
            'created_count' => count($created),
            'created' => $created,
            'skipped_existing_count' => count($skipped),
            'skipped_existing' => $skipped,
            'unmatched_site_count' => count($unmatched),
            'unmatched_site' => $unmatched,
            'general_count' => count($general),
            'general' => $general,
        ]);
    }

    /** The service-center number embedded in a device name (…SC208… → "208"). */
    private function siteNumber(string $name): ?string
    {
        return preg_match('/SC0*(\d{1,4})/i', $name, $m) ? $this->pad($m[1]) : null;
    }

    /** Zero-pad a service-center number to 3 digits (13 → 013), matching Site Import. */
    private function pad(string $n): string
    {
        return str_pad(preg_replace('/\D/', '', $n) ?: '0', 3, '0', STR_PAD_LEFT);
    }
}
