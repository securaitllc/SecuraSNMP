<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CveRecord extends Model
{
    protected $fillable = [
        'cve_id', 'cvss_score', 'severity', 'summary', 'reference_url', 'published_at', 'source',
    ];

    protected $casts = [
        'cvss_score' => 'float',
        'published_at' => 'datetime',
    ];

    /** CVSS band → severity label (NVD v3 cut points). */
    public static function severityForScore(?float $score): string
    {
        return match (true) {
            $score === null => 'none',
            $score >= 9.0 => 'critical',
            $score >= 7.0 => 'high',
            $score >= 4.0 => 'medium',
            $score > 0.0 => 'low',
            default => 'none',
        };
    }
}
