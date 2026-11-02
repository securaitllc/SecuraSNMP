<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceHealth extends Model
{
    use HasFactory;

    protected $table = 'device_health';

    protected $fillable = [
        'device_id',
        'cpu_pct',
        'mem_pct',
        'mem_reclaimable_mb',
        'swap_used_mb',
        'temperature_c',
        'uptime_seconds',
        'polled_at',
    ];

    protected $casts = [
        'cpu_pct' => 'float',
        'mem_pct' => 'float',
        'mem_reclaimable_mb' => 'integer',
        'swap_used_mb' => 'integer',
        'temperature_c' => 'float',
        'uptime_seconds' => 'integer',
        'polled_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
