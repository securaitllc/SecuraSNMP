<?php

namespace App\Services\Vuln;

/**
 * Normalises the three vendors' firmware strings into comparable numeric tuples so a
 * device version can be tested against a CVE's affected range.
 *
 * The vendors format versions very differently:
 *   fortigate    "v7.2.10,build1706,240918 (GA.M)"  → 7.2.10
 *   silverpeak   "9.3.8.1_96913"                     → 9.3.8.1
 *   juniper      "20.4R3-S2.6" / "15.1X53-D58.3"     → 20.4R3-S2.6 (letters kept)
 *
 * After vendor-specific cleanup every version is reduced to its ordered list of
 * integers and compared lexicographically with zero-padding. That is exact for
 * Forti/SilverPeak dotted versions and a sound ordering for JunOS WITHIN a release
 * train (where CVE ranges almost always sit). Cross-train JunOS ordering is
 * best-effort — findings always carry the matched constraint as visible evidence.
 *
 * Pure functions only; no framework, no I/O — trivially unit-testable.
 */
class VersionMatcher
{
    /** Strip vendor cruft down to the comparable version token. */
    public static function clean(string $vendor, string $raw): string
    {
        $raw = trim($raw);

        return match ($vendor) {
            // "v7.2.10,build1706,240918 (GA.M)" → "7.2.10"
            'fortigate' => preg_match('/v?(\d+(?:\.\d+)+)/', $raw, $m) ? $m[1] : $raw,
            // "9.3.8.1_96913" → "9.3.8.1" (drop the build suffix after "_")
            'silverpeak' => explode('_', $raw)[0],
            // JunOS keeps its letters; numbers are extracted for comparison.
            default => $raw,
        };
    }

    /** Ordered list of integers in a version, e.g. "20.4R3-S2.6" → [20,4,3,2,6]. */
    public static function tuple(string $vendor, string $raw): array
    {
        $clean = self::clean($vendor, $raw);

        return preg_match_all('/\d+/', $clean, $m) ? array_map('intval', $m[0]) : [];
    }

    /**
     * Canonical release identity for exact-enumeration matching, build/spin-insensitive.
     *
     * JunOS encodes the release across distinct fields — major.minor, a train letter
     * (R/X/F), an R/X number, and an optional S/D service number — plus a trailing build
     * spin that is NOT part of the release identity. "20.4R3-S2.6" and "20.4R3-S2" are the
     * same release; "20.4R3" (S0) and "20.4R3-S9" (S9) are NOT. Returns
     * [major, minor, letterRank, rNum, sNum]. Non-JunOS falls back to the digit tuple.
     */
    public static function canonical(string $vendor, string $raw): array
    {
        if ($vendor === 'juniper') {
            $raw = trim($raw);
            // R/F trains: 20.4R3, 20.4R3-S2(.6), 21.2R3.8
            if (preg_match('/^(\d+)\.(\d+)([RF])(\d+)(?:-?S(\d+))?/i', $raw, $m)) {
                $rank = ['R' => 0, 'F' => 2][strtoupper($m[3])] ?? 9;

                return [(int) $m[1], (int) $m[2], $rank, (int) $m[4], (int) ($m[5] ?? 0)];
            }
            // X trains use a D service number: 15.1X53-D58.3
            if (preg_match('/^(\d+)\.(\d+)X(\d+)(?:-?D(\d+))?/i', $raw, $m)) {
                return [(int) $m[1], (int) $m[2], 1, (int) $m[3], (int) ($m[4] ?? 0)];
            }
        }

        return self::tuple($vendor, $raw);
    }

    /**
     * Exact-release match: the device is running the specific enumerated release,
     * ignoring build spin. Distinguishes 20.4R3-S2(.6) from the patched 20.4R3-S9.
     */
    public static function matchesExact(string $vendor, string $deviceRaw, string $affectVersion): bool
    {
        $device = self::canonical($vendor, $deviceRaw);
        $affect = self::canonical($vendor, $affectVersion);

        return $device !== [] && $device === $affect;
    }

    /** Lexicographic compare of two tuples, zero-padded to equal length. -1/0/1. */
    public static function compareTuples(array $a, array $b): int
    {
        $len = max(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $x = $a[$i] ?? 0;
            $y = $b[$i] ?? 0;
            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        return 0;
    }

    /**
     * Is $deviceRaw inside the affected range? Bounds are inclusive/exclusive per
     * the flags — defaults are lower-inclusive, upper-exclusive (the common "affected
     * from X, fixed in Y" case, and the starter catalog's convention). NVD supplies
     * the exact inclusivity. Either bound may be null (open-ended). A device with no
     * parseable version never matches.
     */
    public static function inRange(
        string $vendor,
        string $deviceRaw,
        ?string $introduced,
        ?string $fixed,
        bool $introducedInclusive = true,
        bool $fixedInclusive = false,
    ): bool {
        $device = self::tuple($vendor, $deviceRaw);
        if ($device === []) {
            return false;
        }

        if ($introduced !== null && $introduced !== '') {
            $cmp = self::compareTuples($device, self::tuple($vendor, $introduced));
            // Below the lower bound (or at it when the bound is exclusive) → no.
            if ($cmp < 0 || ($cmp === 0 && ! $introducedInclusive)) {
                return false;
            }
        }

        if ($fixed !== null && $fixed !== '') {
            $cmp = self::compareTuples($device, self::tuple($vendor, $fixed));
            // Above the upper bound (or at it when the bound is exclusive) → no.
            if ($cmp > 0 || ($cmp === 0 && ! $fixedInclusive)) {
                return false;
            }
        }

        return true;
    }
}
