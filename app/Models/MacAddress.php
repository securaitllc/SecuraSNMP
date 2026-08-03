<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MacAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'device_interface_id',
        'mac',
        'vlan',
        'oui_vendor',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'vlan' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceInterface(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class);
    }
}
