<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceNextHop extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'ip_address',
        'interface',
        'reachability',
        'uptime',
        'status',
        'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(NextHopAlert::class);
    }
}
