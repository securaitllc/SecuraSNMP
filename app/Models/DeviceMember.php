<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'member_id',
        'serial_number',
        'model',
        'role',
        'sw_version',
        'priority',
        'status',
        'last_seen_at',
        'absent_since',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'absent_since' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
