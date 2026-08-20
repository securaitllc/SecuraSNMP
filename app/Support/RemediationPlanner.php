<?php

namespace App\Support;

/**
 * Turns a device's matched CVE constraints into ONE recommended upgrade target —
 * the release that clears the most findings. Parses the two constraint shapes the
 * catalog emits:
 *   Juniper  "20.4 before 20.4R3-S9"        → fix 20.4R3-S9
 *   Juniper  "12.3 (end-of-life, affected)" → no in-train fix (EOL)
 *   FortiGate "≥ 7.2.0 < 7.2.12"            → fix 7.2.12
 * A device is flagged EOL when any finding has no in-train fix, or its current train
 * is itself out of support — either way the answer is "move to a supported release".
 */
class RemediationPlanner
{
    /**
     * @param  list<string|null>  $constraints
     * @return array{target: string|null, eol: bool}
     */
    public static function plan(?string $vendor, ?string $currentVersion, array $constraints): array
    {
        $fixes = [];
        $unresolved = false;

        foreach ($constraints as $c) {
            $fix = self::fixVersion((string) $c);
            if ($fix === null) {
                $unresolved = true; // an EOL/pre-fix constraint — no clean in-train patch
            } else {
                $fixes[] = $fix;
            }
        }

        $eol = $unresolved || self::trainIsEol($vendor, $currentVersion);
        $target = $fixes === [] ? null : self::highest($fixes);

        return ['target' => $target, 'eol' => $eol];
    }

    /** The fixed release named by a single constraint, or null if it names none. */
    private static function fixVersion(string $constraint): ?string
    {
        // Juniper: "<train> before <fix>"
        if (preg_match('/before\s+([0-9][0-9A-Za-z.\-]*)/i', $constraint, $m)) {
            return $m[1];
        }
        // FortiGate range upper bound: "... < X" or "... ≤ X"
        if (preg_match('/(?:<|≤)\s*v?([0-9][0-9.]*)/u', $constraint, $m)) {
            return $m[1];
        }

        return null; // "(end-of-life, affected)" / "(affected, pre-fix)"
    }

    /** True if the current train is past vendor support (no patch exists on it). */
    private static function trainIsEol(?string $vendor, ?string $version): bool
    {
        if ($version === null || $version === '') {
            return false;
        }
        $v = strtolower($version);

        if ($vendor === 'juniper') {
            // 12.x/13/14/15.x (incl. 15.1X) are all EOL; 16 is the first still-serviced train.
            if (preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
                return (int) $m[1] < 16;
            }
        }
        if ($vendor === 'fortigate') {
            if (preg_match('/^v?(\d+)\.(\d+)/', $v, $m)) {
                return (int) $m[1] < 7; // FortiOS < 7.0 is EOL
            }
        }

        return false;
    }

    /** @param list<string> $versions */
    private static function highest(array $versions): string
    {
        $best = $versions[0];
        foreach ($versions as $v) {
            if (self::cmp($v, $best) > 0) {
                $best = $v;
            }
        }

        return $best;
    }

    /** Numeric-tuple compare (7.4.10 > 7.4.9; 20.4R3-S9 > 20.4R3-S4). */
    private static function cmp(string $a, string $b): int
    {
        $ta = self::tuple($a);
        $tb = self::tuple($b);
        $n = max(count($ta), count($tb));
        for ($i = 0; $i < $n; $i++) {
            $x = $ta[$i] ?? 0;
            $y = $tb[$i] ?? 0;
            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        return 0;
    }

    /** @return list<int> */
    private static function tuple(string $v): array
    {
        return array_map('intval', array_values(array_filter(preg_split('/[^0-9]+/', $v), fn ($p) => $p !== '')));
    }
}
