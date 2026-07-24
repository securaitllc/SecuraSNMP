<?php

namespace App\Support;

/**
 * Minimal LCS-based line diff for config drift review. Returns a list of
 * ['op' => ' '|'-'|'+', 'text' => string]. Falls back to a set-based
 * added/removed summary for very large configs to bound memory.
 */
class LineDiff
{
    private const MAX_LCS_LINES = 2000;

    /**
     * @return array<int, array{op: string, text: string}>
     */
    public static function diff(string $old, string $new): array
    {
        $a = preg_split('/\r?\n/', rtrim($old, "\r\n"));
        $b = preg_split('/\r?\n/', rtrim($new, "\r\n"));

        if (count($a) > self::MAX_LCS_LINES || count($b) > self::MAX_LCS_LINES) {
            return self::setDiff($a, $b);
        }

        $m = count($a);
        $n = count($b);
        $c = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $c[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $c[$i - 1][$j - 1] + 1
                    : max($c[$i - 1][$j], $c[$i][$j - 1]);
            }
        }

        $out = [];
        $i = $m;
        $j = $n;
        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                $out[] = ['op' => ' ', 'text' => $a[$i - 1]];
                $i--;
                $j--;
            } elseif ($c[$i - 1][$j] >= $c[$i][$j - 1]) {
                $out[] = ['op' => '-', 'text' => $a[$i - 1]];
                $i--;
            } else {
                $out[] = ['op' => '+', 'text' => $b[$j - 1]];
                $j--;
            }
        }
        while ($i > 0) {
            $out[] = ['op' => '-', 'text' => $a[--$i]];
        }
        while ($j > 0) {
            $out[] = ['op' => '+', 'text' => $b[--$j]];
        }

        return array_reverse($out);
    }

    /** @return array<int, array{op: string, text: string}> */
    private static function setDiff(array $a, array $b): array
    {
        $out = [];
        foreach (array_diff($a, $b) as $line) {
            $out[] = ['op' => '-', 'text' => $line];
        }
        foreach (array_diff($b, $a) as $line) {
            $out[] = ['op' => '+', 'text' => $line];
        }

        return $out;
    }
}
