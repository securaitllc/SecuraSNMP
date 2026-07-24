<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CircuitMetricHistory extends Model
{
    // Eloquent would otherwise pluralize this to "circuit_metric_histories".
    protected $table = 'circuit_metric_history';

    protected $fillable = [
        'circuit_id',
        'recorded_at',
        'response_time_ms',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'response_time_ms' => 'float',
    ];

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }
}
