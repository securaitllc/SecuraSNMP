<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TunnelMetricHistory extends Model
{
    protected $table = 'tunnel_metric_history';

    protected $fillable = [
        'tunnel_id',
        'recorded_at',
        'status',
        'in_discards_delta',
        'out_discards_delta',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(Tunnel::class);
    }
}
