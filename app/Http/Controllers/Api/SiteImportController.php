<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk-imports sites from an external directory feed. The payload is a neutral
 * list of sites with optional contacts — the caller maps whatever directory it
 * has into this shape. Two safeguards:
 *   - Duplicate physical buildings collapse to ONE site (same address ignoring
 *     suite/unit → keep the lowest number), because one building = one network.
 *   - Sites already in the app are skipped (matched by number), never duplicated.
 * Pass dry_run to preview the create / skip / merge decisions without writing.
 */
class SiteImportController extends Controller
{
    private const CONTACT_FIELDS = [
        'gm_name', 'gm_phone', 'gm_ext',
        'om_name', 'om_phone', 'om_ext',
        'sm_name', 'sm_phone', 'sm_ext',
    ];

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sites' => ['required', 'array', 'min:1'],
            'sites.*.number' => ['required', 'string', 'max:16'],
            'sites.*.name' => ['required', 'string', 'max:255'],
            'sites.*.address' => ['nullable', 'string', 'max:512'],
            'sites.*.region' => ['nullable', 'string', 'max:255'],
            'sites.*.main_phone' => ['nullable', 'string', 'max:64'],
            'sites.*.fax' => ['nullable', 'string', 'max:64'],
            'sites.*.gm_name' => ['nullable', 'string', 'max:255'],
            'sites.*.gm_phone' => ['nullable', 'string', 'max:64'],
            'sites.*.gm_ext' => ['nullable', 'string', 'max:32'],
            'sites.*.om_name' => ['nullable', 'string', 'max:255'],
            'sites.*.om_phone' => ['nullable', 'string', 'max:64'],
            'sites.*.om_ext' => ['nullable', 'string', 'max:32'],
            'sites.*.sm_name' => ['nullable', 'string', 'max:255'],
            'sites.*.sm_phone' => ['nullable', 'string', 'max:64'],
            'sites.*.sm_ext' => ['nullable', 'string', 'max:32'],
            'dry_run' => ['boolean'],
        ]);
        $dryRun = (bool) ($request->boolean('dry_run'));

        // 1. Collapse same-building entries → keep the lowest number per address.
        $byBuilding = [];
        foreach ($data['sites'] as $s) {
            $addr = $this->normalizeAddress($s['address'] ?? '');
            $key = $addr !== '' ? 'a:'.$addr : 'n:'.$this->pad($s['number']); // no address = keep distinct
            if (! isset($byBuilding[$key]) || $this->pad($s['number']) < $this->pad($byBuilding[$key]['number'])) {
                $byBuilding[$key] = $s;
            }
        }
        $kept = array_map(fn ($s) => $this->pad($s['number']), array_values($byBuilding));
        $merged = array_values(array_diff(
            array_map(fn ($s) => $this->pad($s['number']), $data['sites']),
            $kept,
        ));

        // 2. Existing sites keyed by number — its own site_number, or a #NNN in the
        //    name (many were created before the number field was set).
        $existingByNum = [];
        foreach (Site::all() as $site) {
            if ($site->site_number) {
                $existingByNum[$this->pad($site->site_number)] ??= $site;
            }
            if (preg_match('/#?(\d{2,4})\b/', (string) $site->name, $m)) {
                $existingByNum[$this->pad($m[1])] ??= $site;
            }
        }

        // The site fields we can fill from the directory (never the name).
        $fillable = array_merge(['site_number', 'region', 'address', 'main_phone', 'fax'], self::CONTACT_FIELDS);

        $created = [];
        $enriched = [];
        $skipped = [];
        foreach (array_values($byBuilding) as $s) {
            $n = $this->pad($s['number']);
            $payload = array_merge(['site_number' => $n], collect($fillable)
                ->mapWithKeys(fn ($f) => [$f => $f === 'site_number' ? $n : ($s[$f] ?? null)])->all());

            $site = $existingByNum[$n] ?? null;
            if ($site) {
                // ENRICH: fill only the fields that are currently empty on the site
                // from the directory — never overwrite a value already there, never
                // add a contact the directory doesn't have (skip empty).
                $update = [];
                foreach ($fillable as $f) {
                    $incoming = trim((string) ($payload[$f] ?? ''));
                    if ($incoming !== '' && trim((string) $site->getAttribute($f)) === '') {
                        $update[$f] = $incoming;
                    }
                }
                if ($update !== []) {
                    if (! $dryRun) {
                        $site->update($update);
                    }
                    $enriched[] = $n;
                } else {
                    $skipped[] = $n;
                }

                continue;
            }

            if (! $dryRun) {
                Site::create(array_merge([
                    'name' => "#{$n} ".trim($s['name']),
                    'site_type' => 'branch',
                ], array_filter($payload, fn ($v) => $v !== null && $v !== '')));
            }
            $created[] = $n;
        }
        sort($created);
        sort($enriched);
        sort($skipped);
        sort($merged);

        return response()->json([
            'dry_run' => $dryRun,
            'created_count' => count($created),
            'created' => $created,
            'enriched_count' => count($enriched),
            'enriched' => $enriched,
            'skipped_existing_count' => count($skipped),
            'skipped_existing' => $skipped,
            'merged_duplicates_count' => count($merged),
            'merged_duplicates' => $merged,
        ]);
    }

    /** Zero-pad a site number to 3 digits (01 → 001). */
    private function pad(string $n): string
    {
        return str_pad(preg_replace('/\D/', '', $n) ?: '0', 3, '0', STR_PAD_LEFT);
    }

    /** Address reduced to its building — suite/unit/etc dropped — for dedupe. */
    private function normalizeAddress(?string $a): string
    {
        $a = strtolower((string) $a);
        // Drop suite/unit designators (NOT "fl" — that's the state Florida, and the
        // ZIP must stay so two different buildings never collapse together).
        $a = preg_replace('/\b(ste|suite|bldg|building|unit|apt|rm|room)\s*[\w-]+/', ' ', $a);
        $a = preg_replace('/[^a-z0-9]+/', ' ', $a);

        return trim(preg_replace('/\s+/', ' ', $a));
    }
}
