<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of the last CVE-feed pull for one vendor/product CPE.
 *
 * Exists so that "this target returned nothing" is a fact on a page somewhere instead
 * of a line in a log nobody reads. See the migration for why.
 */
class VulnFeedTarget extends Model
{
    protected $fillable = [
        'cpe', 'vendor', 'product', 'status', 'cve_count', 'total_results', 'message', 'checked_at',
    ];

    protected $casts = [
        'cve_count' => 'integer',
        'total_results' => 'integer',
        'checked_at' => 'datetime',
    ];

    /** Statuses that mean the catalog is not getting what it asked for. */
    public const DEGRADED = ['empty', 'no_ranges', 'error'];

    /** Record one target's result from NvdFeedSource::reports(). */
    public static function record(array $report): self
    {
        return self::updateOrCreate(
            ['cpe' => $report['cpe']],
            [
                'vendor' => $report['vendor'],
                'product' => $report['product'] ?? null,
                'status' => $report['status'],
                'cve_count' => $report['cve_count'] ?? 0,
                'total_results' => $report['total_results'] ?? 0,
                'message' => $report['message'] ?? null,
                'checked_at' => now(),
            ]
        );
    }

    public function isDegraded(): bool
    {
        return in_array($this->status, self::DEGRADED, true);
    }
}
