<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsintIoc extends Model
{
    protected $fillable = ['case_id', 'type', 'value', 'confidence', 'source', 'context', 'first_seen', 'added_by'];

    protected $casts = [
        'context' => 'array',
        'first_seen' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(OsintCase::class, 'case_id');
    }
}
