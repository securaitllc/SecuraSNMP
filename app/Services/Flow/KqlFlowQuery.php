<?php

namespace App\Services\Flow;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * A safe, curated KQL SUBSET over the flow record — the query language behind the
 * Flows search bar. NOT full Kusto: just the clauses that matter for flows.
 *
 *   Flows | where SrcIP in (cidr("10.86.10.0/24")) and Port == 443
 *         and App == "Microsoft 365" and Bytes > 1M
 *
 * Security: fields and operators are WHITELISTED and every value is passed as a
 * binding — nothing from the query string is ever interpolated into SQL. An unknown
 * field/operator throws (surfaced to the user as a query error), never silently runs.
 * The time window is applied separately by the caller (it's the time picker, not a
 * clause), matching the UI.
 */
class KqlFlowQuery
{
    /** KQL field → [column, kind]. kind drives value parsing + operator validity. */
    private const FIELDS = [
        'srcip' => ['src_ip', 'ip'],
        'dstip' => ['dst_ip', 'ip'],
        'srcport' => ['src_port', 'int'],
        'dstport' => ['dst_port', 'int'],
        'port' => ['__port', 'int'],       // matches src OR dst port
        'protocol' => ['protocol', 'str'],
        'app' => ['app', 'str'],
        'category' => ['app_category', 'str'],
        'direction' => ['direction', 'str'],
        'bytes' => ['bytes', 'int'],
        'packets' => ['packets', 'int'],
    ];

    private const OPS = ['==', '!=', '>=', '<=', '>', '<', 'contains', 'in'];

    /**
     * Parse the where-predicate out of a KQL string into a flat list of clauses joined
     * by AND. (Leading "Flows |", "| where", and any trailing "| summarize/top" stages
     * are handled by the caller; this focuses on the filter.)
     *
     * @return list<array{field:string, op:string, value:mixed, kind:string}>
     */
    public function parseWhere(string $kql): array
    {
        // Isolate the where stage: drop a leading "Flows", take up to the first pipe
        // that begins a summarize/top, and drop a leading "where".
        $s = trim($kql);
        $s = preg_replace('/^\s*Flows\s*\|?\s*/i', '', $s);
        // A query that is ONLY an aggregation (no where filter) has no clauses.
        if (preg_match('/^\s*(summarize|top)\b/i', $s)) {
            return [];
        }
        // Keep only the first stage (before a | that begins summarize/top).
        $s = preg_split('/\|\s*(summarize|top)\b/i', $s)[0];
        $s = trim($s);
        $s = preg_replace('/^\s*(\|\s*)?where\s+/i', '', $s);
        $s = trim($s);
        if ($s === '') {
            return [];
        }

        $clauses = [];
        foreach ($this->splitAnd($s) as $part) {
            $clauses[] = $this->parseClause(trim($part));
        }

        return $clauses;
    }

    /**
     * Apply parsed clauses to a flow query, safely (bound values, whitelisted columns).
     *
     * @param  list<array{field:string, op:string, value:mixed, kind:string}>  $clauses
     */
    public function apply(Builder $query, array $clauses): Builder
    {
        foreach ($clauses as $c) {
            $this->applyClause($query, $c);
        }

        return $query;
    }

    /** Convenience: parse + apply in one call. */
    public function filter(Builder $query, string $kql): Builder
    {
        return $this->apply($query, $this->parseWhere($kql));
    }

    /**
     * Parse the optional aggregation tail:  | summarize sum(Bytes|Packets) by <Field>
     * and | top N [by …]. Returns null when the query is a plain row filter.
     *
     * @return array{by:string, by_label:string, metric:string, top:int}|null
     */
    public function parsePipeline(string $kql): ?array
    {
        if (! preg_match('/\|\s*summarize\s+sum\(\s*(bytes|packets)\s*\)\s+by\s+([A-Za-z]+)/i', $kql, $m)) {
            return null;
        }
        $byKey = strtolower($m[2]);
        if (! isset(self::FIELDS[$byKey]) || self::FIELDS[$byKey][0] === '__port') {
            throw new InvalidArgumentException("Can't summarize by: {$m[2]}");
        }
        $top = 20;
        if (preg_match('/\|\s*top\s+(\d+)/i', $kql, $t)) {
            $top = min(200, max(1, (int) $t[1]));
        }

        return [
            'by' => self::FIELDS[$byKey][0],
            'by_label' => $m[2],
            'metric' => strtolower($m[1]),
            'top' => $top,
        ];
    }

    /** Split on top-level " and " only (not inside parentheses). */
    private function splitAnd(string $s): array
    {
        $parts = [];
        $depth = 0;
        $buf = '';
        $tokens = preg_split('/(\band\b|[()])/i', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $t) {
            if ($t === '(') {
                $depth++;
                $buf .= $t;
            } elseif ($t === ')') {
                $depth--;
                $buf .= $t;
            } elseif (strcasecmp(trim($t), 'and') === 0 && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
            } else {
                $buf .= $t;
            }
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }

    /**
     * @return array{field:string, op:string, value:mixed, kind:string}
     */
    private function parseClause(string $clause): array
    {
        if (! preg_match('/^([A-Za-z]+)\s*(==|!=|>=|<=|>|<|contains|in)\s*(.+)$/is', $clause, $m)) {
            throw new InvalidArgumentException("Can't parse: \"{$clause}\"");
        }
        $fieldKey = strtolower($m[1]);
        $op = strtolower($m[2]);
        $rawValue = trim($m[3]);

        if (! isset(self::FIELDS[$fieldKey])) {
            throw new InvalidArgumentException("Unknown field: {$m[1]}");
        }
        if (! in_array($op, self::OPS, true)) {
            throw new InvalidArgumentException("Unknown operator: {$op}");
        }
        [$column, $kind] = self::FIELDS[$fieldKey];

        return [
            'field' => $column,
            'op' => $op,
            'kind' => $kind,
            'value' => $this->parseValue($rawValue, $kind, $op),
        ];
    }

    /** Parse the RHS: in-list, cidr(), quoted string, number(+K/M/G suffix), or bareword. */
    private function parseValue(string $raw, string $kind, string $op): mixed
    {
        if ($op === 'in') {
            // in (cidr("...")) → a special cidr marker; in (a, b, c) → list.
            if (preg_match('/^\(\s*cidr\(\s*"([^"]+)"\s*\)\s*\)$/i', $raw, $m)) {
                return ['__cidr' => $m[1]];
            }
            $inner = trim($raw, "() \t");
            $items = array_map(fn ($v) => $this->scalar(trim($v), $kind), preg_split('/\s*,\s*/', $inner));

            return $items;
        }
        // A bare cidr("...") on == is treated as membership too.
        if (preg_match('/^cidr\(\s*"([^"]+)"\s*\)$/i', $raw, $m)) {
            return ['__cidr' => $m[1]];
        }

        return $this->scalar($raw, $kind);
    }

    private function scalar(string $raw, string $kind): mixed
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return substr($raw, 1, -1);
        }
        if ($kind === 'int' || preg_match('/^\d+(\.\d+)?[KMGkmg]?$/', $raw)) {
            return $this->number($raw);
        }

        return $raw; // bareword (e.g. Direction == outbound, Protocol == tcp)
    }

    private function number(string $raw): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg])$/', $raw, $m)) {
            $mult = ['k' => 1_000, 'm' => 1_000_000, 'g' => 1_000_000_000][strtolower($m[2])];

            return (int) round((float) $m[1] * $mult);
        }

        return (int) $raw;
    }

    private function applyClause(Builder $query, array $c): void
    {
        $col = $c['field'];
        $op = $c['op'];
        $val = $c['value'];

        // Port matches EITHER src or dst.
        if ($col === '__port') {
            $query->where(function (Builder $q) use ($op, $val) {
                $this->comparison($q, 'src_port', $op, $val, 'or');
                $this->comparison($q, 'dst_port', $op, $val, 'or');
            });

            return;
        }

        // cidr membership on an IP column.
        if (is_array($val) && isset($val['__cidr'])) {
            $this->applyCidr($query, $col, $val['__cidr'], 'and');

            return;
        }

        $this->comparison($query, $col, $op, $val, 'and');
    }

    private function comparison(Builder $q, string $col, string $op, mixed $val, string $bool): void
    {
        $method = $bool === 'or' ? 'orWhere' : 'where';

        if ($op === 'in' && is_array($val)) {
            $bool === 'or' ? $q->orWhereIn($col, $val) : $q->whereIn($col, $val);

            return;
        }
        if ($op === 'contains') {
            $q->{$method}($col, 'like', '%'.$this->escapeLike((string) $val).'%');

            return;
        }
        $sqlOp = ['==' => '=', '!=' => '!=', '>' => '>', '<' => '<', '>=' => '>=', '<=' => '<='][$op] ?? '=';
        $q->{$method}($col, $sqlOp, $val);
    }

    /** IPv4 CIDR → a prefix LIKE for octet-aligned masks; a broader superset otherwise. */
    private function applyCidr(Builder $q, string $col, string $cidr, string $bool): void
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $bits = (int) $bits;
        $octets = explode('.', $net);
        $keep = intdiv($bits, 8); // whole octets that are fixed
        if ($keep >= 4 || $bits === 32) {
            $q->where($col, $net);

            return;
        }
        $prefix = implode('.', array_slice($octets, 0, max(1, $keep)));
        $q->where($col, 'like', $this->escapeLike($prefix).'.%');
    }

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }
}
