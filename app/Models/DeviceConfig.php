<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'content',
        'hash',
        'line_count',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        // Device configs routinely contain secrets (SNMP communities, keys,
        // password hashes) — encrypt the stored config at rest.
        'content' => 'encrypted',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
