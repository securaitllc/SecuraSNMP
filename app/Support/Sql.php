<?php

namespace App\Support;

/**
 * SQL helpers shared across controllers.
 */
class Sql
{
    /**
     * Escape a user term for use inside a LIKE pattern so `%` and `_` are matched
     * literally instead of acting as wildcards. Without this a bare `%` forces a
     * full-table scan (query amplification / mild DoS — pentest L-06). The backslash
     * escape char is MySQL's default and matches the KQL engine's own escaping.
     */
    public static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }
}
