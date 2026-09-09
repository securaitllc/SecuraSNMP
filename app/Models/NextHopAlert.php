<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Observers\NextHopAlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([NextHopAlertObserver::class])]
class NextHopAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'device_next_hop_id',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function nextHop(): BelongsTo
    {
        return $this->belongsTo(DeviceNextHop::class, 'device_next_hop_id');
    }
}
