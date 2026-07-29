<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CveAffect extends Model
{
    protected $fillable = [
        'cve_id', 'vendor', 'product', 'version_introduced', 'version_fixed', 'constraint_label',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(CveRecord::class, 'cve_id', 'cve_id');
    }
}
