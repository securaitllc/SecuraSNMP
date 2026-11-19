<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscoveryScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subnets',
        'snmp_credential_id',
        'user_id',
        'status',
        'hosts_total',
        'hosts_responded',
        'devices_found',
        'started_at',
        'finished_at',
        'error',
    ];

    protected $casts = [
        'subnets' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(SnmpCredential::class, 'snmp_credential_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discoveredDevices(): HasMany
    {
        return $this->hasMany(DiscoveredDevice::class);
    }
}
