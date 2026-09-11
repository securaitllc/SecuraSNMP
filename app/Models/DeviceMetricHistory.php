<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMetricHistory extends Model
{
    // Eloquent would otherwise pluralize this to "device_metric_histories".
    protected $table = 'device_metric_history';

    protected $fillable = [
        'device_id',
        'recorded_at',
        'response_time_ms',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'response_time_ms' => 'float',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
