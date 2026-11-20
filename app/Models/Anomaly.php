<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Anomaly extends Model
{
    protected $fillable = [
        'entity_type', 'entity_id', 'metric', 'direction',
        'baseline', 'observed', 'z_score', 'detected_at', 'last_seen_at', 'resolved_at',
    ];

    protected $casts = [
        'baseline' => 'float',
        'observed' => 'float',
        'z_score' => 'float',
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen($q)
    {
        return $q->whereNull('resolved_at');
    }
}
