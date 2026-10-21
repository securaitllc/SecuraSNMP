<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * SQL helpers shared across controllers.
 */
class Sql
{
    /**
     * Escape a user term for use inside a LIKE pattern so `%` and `_` are matched
     * literally instead of acting as wildcards. Without this a bare `%` forces a
     * full-table scan (query amplification / mild DoS — pentest L-06). Pair it with
     * whereLike(), which declares the backslash as the escape char explicitly.
     */
    public static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /**
     * `$column LIKE ? ESCAPE ?` for a pattern built with escapeLike(). SQLite has no
     * default escape char and MySQL loses its implicit one under NO_BACKSLASH_ESCAPES
     * (INJ-02), so the escape is stated, and bound so the literal can't be mis-quoted.
     */
    public static function whereLike(Builder $query, string $column, string $pattern, string $boolean = 'and'): Builder
    {
        return $query->whereRaw($query->getGrammar()->wrap($column).' LIKE ? ESCAPE ?', [$pattern, '\\'], $boolean);
    }

    /** whereLike() over several columns, OR'd inside one group. */
    public static function whereLikeAny(Builder $query, array $columns, string $pattern): Builder
    {
        return $query->where(function ($group) use ($columns, $pattern) {
            foreach ($columns as $column) {
                self::whereLike($group, $column, $pattern, 'or');
            }
        });
    }
}
