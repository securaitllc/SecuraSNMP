<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LldpNeighbor extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'local_port',
        'remote_sysname',
        'remote_sysdesc',
        'remote_chassis_id',
        'remote_capabilities',
        'neighbor_type',
        'remote_port',
        'remote_mgmt_addr',
        'stp_state',
        'remote_device_id',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function remoteDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'remote_device_id');
    }
}
