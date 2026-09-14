<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnmpTrap extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'source_ip',
        'trap_oid',
        'message',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
