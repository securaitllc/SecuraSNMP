<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceHealthHistory extends Model
{
    use HasFactory;

    protected $table = 'device_health_history';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'recorded_at',
        'cpu_pct',
        'mem_pct',
        'temperature_c',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'cpu_pct' => 'float',
        'mem_pct' => 'float',
        'temperature_c' => 'float',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
