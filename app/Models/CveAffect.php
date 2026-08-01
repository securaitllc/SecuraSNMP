<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CveAffect extends Model
{
    protected $fillable = [
        'cve_id', 'vendor', 'product', 'version_introduced', 'version_fixed',
        'introduced_inclusive', 'fixed_inclusive', 'exact_match', 'constraint_label',
    ];

    protected $casts = [
        'introduced_inclusive' => 'boolean',
        'fixed_inclusive' => 'boolean',
        'exact_match' => 'boolean',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(CveRecord::class, 'cve_id', 'cve_id');
    }
}
