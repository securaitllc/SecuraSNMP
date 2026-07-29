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
     * Is $deviceRaw inside [introduced, fixed)? introduced inclusive, fixed
     * exclusive; either bound may be null (open-ended). A device with no parseable
     * version never matches.
     */
    public static function inRange(string $vendor, string $deviceRaw, ?string $introduced, ?string $fixed): bool
    {
        $device = self::tuple($vendor, $deviceRaw);
        if ($device === []) {
            return false;
        }

        if ($introduced !== null && $introduced !== '') {
            if (self::compareTuples($device, self::tuple($vendor, $introduced)) < 0) {
                return false; // older than the first affected version
            }
        }

        if ($fixed !== null && $fixed !== '') {
            if (self::compareTuples($device, self::tuple($vendor, $fixed)) >= 0) {
                return false; // at or past the fixed version
            }
        }

        return true;
    }
}
