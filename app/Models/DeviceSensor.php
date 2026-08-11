<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSensor extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'name',
        'sensor_type',
        'value',
        'unit',
        'status',
        'last_seen_at',
    ];

    protected $casts = [
        'value' => 'float',
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
