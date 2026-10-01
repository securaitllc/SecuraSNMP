<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OsintCase extends Model
{
    protected $fillable = ['case_number', 'title', 'severity', 'status', 'summary', 'mitre', 'owner_id', 'closed_at'];

    protected $casts = [
        'mitre' => 'array',
        'closed_at' => 'datetime',
    ];

    public function iocs(): HasMany
    {
        return $this->hasMany(OsintIoc::class, 'case_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Next case number for the year, e.g. CASE-2026-0043 — gap-tolerant (max+1). */
    public static function nextCaseNumber(): string
    {
        $year = now()->format('Y');
        $last = static::where('case_number', 'like', "CASE-{$year}-%")->max('case_number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('CASE-%s-%04d', $year, $seq);
    }
}
