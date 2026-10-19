<?php

namespace Database\Seeders;

use App\Models\CveAffect;
use App\Models\CveRecord;
use Illuminate\Database\Seeder;

/**
 * Starter CVE catalog — a curated set of well-documented, high-signal CVEs for the
 * vendors/trains actually in the Massey fleet (FortiOS, JunOS, EdgeConnect). It makes
 * the vulnerability feature useful on day one.
 *
 * NOT authoritative: the production path is the live NVD + vendor-PSIRT feed, which
 * supersedes and extends this. Affected ranges here are per-train (introduced = train
 * floor, fixed = the release that patched that train) so a device only matches its own
 * train. Where a train's exact service-release fix is ambiguous under numeric ordering,
 * the range is set conservatively (prefer a false positive over missing a real one).
 * Every finding surfaces its matched range as evidence.
 *
 * Idempotent: safe to run repeatedly.
 */
class CveCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $entry) {
            CveRecord::updateOrCreate(
                ['cve_id' => $entry['cve']],
                [
                    'cvss_score' => $entry['cvss'],
                    'severity' => CveRecord::severityForScore($entry['cvss']),
                    'summary' => $entry['summary'],
                    'reference_url' => 'https://nvd.nist.gov/vuln/detail/'.$entry['cve'],
                    'source' => $entry['source'],
                ]
            );

            foreach ($entry['affects'] as $a) {
                CveAffect::updateOrCreate(
                    [
                        'cve_id' => $entry['cve'],
                        'vendor' => $a['vendor'],
                        'version_introduced' => $a['from'] ?? null,
                    ],
                    [
                        'version_fixed' => $a['fixed'] ?? null,
                        'product' => $a['product'] ?? null,
                        'constraint_label' => $a['label'],
                    ]
                );
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function catalog(): array
    {
        return [
            // ---- FortiOS (FortiGate) ------------------------------------------
            [
                'cve' => 'CVE-2024-21762', 'cvss' => 9.8, 'source' => 'fortinet-psirt',
                'summary' => 'FortiOS SSL VPN out-of-bounds write allows a remote unauthenticated attacker to execute arbitrary code via crafted HTTP requests.',
                'affects' => [
                    ['vendor' => 'fortigate', 'product' => 'fortios', 'from' => '7.4.0', 'fixed' => '7.4.3', 'label' => 'FortiOS 7.4.0–7.4.2'],
                    ['vendor' => 'fortigate', 'product' => 'fortios', 'from' => '7.2.0', 'fixed' => '7.2.7', 'label' => 'FortiOS 7.2.0–7.2.6'],
                ],
            ],
            [
                'cve' => 'CVE-2023-27997', 'cvss' => 9.8, 'source' => 'fortinet-psirt',
                'summary' => 'FortiOS SSL VPN heap-based buffer overflow (XORtigate) permits remote code execution via crafted requests.',
                'affects' => [
                    ['vendor' => 'fortigate', 'product' => 'fortios', 'from' => '7.2.0', 'fixed' => '7.2.5', 'label' => 'FortiOS 7.2.0–7.2.4'],
                    ['vendor' => 'fortigate', 'product' => 'fortios', 'from' => '7.4.0', 'fixed' => '7.4.1', 'label' => 'FortiOS 7.4.0'],
                ],
            ],
            [
                'cve' => 'CVE-2024-23113', 'cvss' => 9.8, 'source' => 'fortinet-psirt',
                'summary' => 'FortiOS fgfmd format-string flaw allows a remote unauthenticated attacker to execute code via crafted requests.',
                'affects' => [
                    ['vendor' => 'fortigate', 'product' => 'fortios', 'from' => '7.4.0', 'fixed' => '7.4.3', 'label' => 'FortiOS 7.4.0–7.4.2'],
                    ['vendor' => 'fortigate', 'product' => 'fortios', 'from' => '7.2.0', 'fixed' => '7.2.7', 'label' => 'FortiOS 7.2.0–7.2.6'],
                ],
            ],

            // ---- JunOS (Juniper EX) -------------------------------------------
            [
                'cve' => 'CVE-2024-21591', 'cvss' => 9.8, 'source' => 'juniper-sirt',
                'summary' => 'Junos OS J-Web out-of-bounds write lets an unauthenticated attacker cause DoS or remote code execution and gain root.',
                'affects' => [
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '20.4', 'fixed' => '20.4R3-S9', 'label' => '20.4 before 20.4R3-S9'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '21.2', 'fixed' => '21.2R4', 'label' => '21.2 before 21.2R3-S7'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '15.1', 'fixed' => '15.2', 'label' => '15.1 (end-of-life, affected)'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '12.3', 'fixed' => '12.4', 'label' => '12.3 (end-of-life, affected)'],
                ],
            ],
            [
                'cve' => 'CVE-2023-36844', 'cvss' => 5.3, 'source' => 'juniper-sirt',
                'summary' => 'Junos OS J-Web PHP external variable modification; chainable with related CVEs to unauthenticated remote code execution.',
                'affects' => [
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '20.4', 'fixed' => '20.4R3-S9', 'label' => '20.4 before 20.4R3-S9'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '21.2', 'fixed' => '21.2R4', 'label' => '21.2 before 21.2R3-S7'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '15.1', 'fixed' => '15.2', 'label' => '15.1 (end-of-life, affected)'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '12.3', 'fixed' => '12.4', 'label' => '12.3 (end-of-life, affected)'],
                ],
            ],
            [
                'cve' => 'CVE-2020-1631', 'cvss' => 9.8, 'source' => 'juniper-sirt',
                'summary' => 'Junos OS J-Web path traversal allows an unauthenticated attacker to read files or achieve remote code execution.',
                'affects' => [
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '15.1', 'fixed' => '15.2', 'label' => '15.1 (affected, pre-fix)'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '12.3', 'fixed' => '12.4', 'label' => '12.3 (affected, pre-fix)'],
                ],
            ],
            [
                'cve' => 'CVE-2021-0254', 'cvss' => 9.8, 'source' => 'juniper-sirt',
                'summary' => 'Junos OS overlayd buffer overflow lets an unauthenticated network attacker execute code or crash the device.',
                'affects' => [
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '15.1', 'fixed' => '15.2', 'label' => '15.1 (affected, pre-fix)'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '12.3', 'fixed' => '12.4', 'label' => '12.3 (affected, pre-fix)'],
                ],
            ],
            [
                'cve' => 'CVE-2022-22221', 'cvss' => 7.8, 'source' => 'juniper-sirt',
                'summary' => 'Junos OS J-Web local file inclusion of a crafted file can lead to local code execution.',
                'affects' => [
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '20.4', 'fixed' => '20.4R3-S4', 'label' => '20.4 before 20.4R3-S4'],
                    ['vendor' => 'juniper', 'product' => 'junos', 'from' => '15.1', 'fixed' => '15.2', 'label' => '15.1 (end-of-life, affected)'],
                ],
            ],

            // ---- EdgeConnect / ECOS (Silver Peak → Aruba → HPE) ---------------
            //
            // This section was empty, on the reasoning that the live feed would cover
            // EdgeConnect. It did not: the feed asked NVD for a CPE that has never
            // existed, so EdgeConnect had neither a live catalog nor a starter one, and
            // the old two-state coverage maths reported the resulting silence as 133
            // clean appliances. An empty section is not a neutral choice when something
            // downstream treats "no ranges" as "no risk".
            //
            // Ranges below are HPE's own, from the gateway advisory of 15 September 2026
            // (HPESBNW05135), and are per-train with the fix release exclusive — which
            // is why a fleet on 9.5.9.0 matches none of them and a fleet on the previous
            // 9.5.8.1 matches all three.
            [
                'cve' => 'CVE-2026-76674', 'cvss' => 9.8, 'source' => 'hpe-psirt',
                'summary' => 'Buffer overflow in the underlying operating system of HPE Networking EdgeConnect SD-WAN gateways allows an unauthenticated remote attacker to execute arbitrary code on the appliance.',
                'affects' => [
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.6.0.0', 'fixed' => '9.6.4.0', 'label' => 'ECOS 9.6.0.0 before 9.6.4.0'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.5.0.0', 'fixed' => '9.5.9.0', 'label' => 'ECOS 9.5.0.0 before 9.5.9.0'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.4.0.0', 'fixed' => '9.4.9.0', 'label' => 'ECOS 9.4.0.0 before 9.4.9.0'],
                ],
            ],
            [
                'cve' => 'CVE-2026-76675', 'cvss' => 9.1, 'source' => 'hpe-psirt',
                'summary' => 'Command injection in the EdgeConnect SD-WAN gateway command-line interface allows an authenticated high-privilege remote attacker to run arbitrary commands on the underlying operating system.',
                'affects' => [
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.6.0.0', 'fixed' => '9.6.4.0', 'label' => 'ECOS 9.6.0.0 before 9.6.4.0'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.5.0.0', 'fixed' => '9.5.9.0', 'label' => 'ECOS 9.5.0.0 before 9.5.9.0'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.4.0.0', 'fixed' => '9.4.9.0', 'label' => 'ECOS 9.4.0.0 before 9.4.9.0'],
                ],
            ],
            [
                'cve' => 'CVE-2026-76678', 'cvss' => 8.8, 'source' => 'hpe-psirt',
                'summary' => 'An EdgeConnect SD-WAN gateway API endpoint allows a low-privilege authenticated remote attacker to escalate privileges and execute system commands as root.',
                'affects' => [
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.6.0.0', 'fixed' => '9.6.4.0', 'label' => 'ECOS 9.6.0.0 before 9.6.4.0'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.5.0.0', 'fixed' => '9.5.9.0', 'label' => 'ECOS 9.5.0.0 before 9.5.9.0'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.4.0.0', 'fixed' => '9.4.9.0', 'label' => 'ECOS 9.4.0.0 before 9.4.9.0'],
                ],
            ],

            // The 2025 gateway advisory. These two are seeded by hand for a specific
            // reason: NVD published the CVEs but never attached CPE applicability to
            // them, so no CPE query of any kind can find them and the live feed is
            // structurally blind to the whole 2025 batch. Hand-seeding is the only
            // coverage they will have until an HPE PSIRT source exists.
            [
                'cve' => 'CVE-2025-37123', 'cvss' => 8.8, 'source' => 'hpe-psirt',
                'summary' => 'OS command injection in the EdgeConnect SD-WAN gateway CLI allows an authenticated remote attacker to execute commands as root.',
                'affects' => [
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.5.0.0', 'fixed' => '9.5.4.1', 'label' => 'ECOS 9.5.0.0 before 9.5.4.1'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.4.0.0', 'fixed' => '9.4.4.2', 'label' => 'ECOS 9.4.0.0 before 9.4.4.2'],
                ],
            ],
            [
                'cve' => 'CVE-2025-37125', 'cvss' => 7.5, 'source' => 'hpe-psirt',
                'summary' => 'Broken access control in EdgeConnect SD-WAN gateway firewall configuration allows an unauthenticated remote attacker to bypass firewall protections.',
                'affects' => [
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.5.0.0', 'fixed' => '9.5.4.1', 'label' => 'ECOS 9.5.0.0 before 9.5.4.1'],
                    ['vendor' => 'silverpeak', 'product' => 'edgeconnect', 'from' => '9.4.0.0', 'fixed' => '9.4.4.2', 'label' => 'ECOS 9.4.0.0 before 9.4.4.2'],
                ],
            ],
        ];
    }
}
