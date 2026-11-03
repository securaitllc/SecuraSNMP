<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterfaceAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'device_interface_id', 'if_index', 'ip',
        'prefix_len', 'netmask', 'is_public', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function interface(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class, 'device_interface_id');
    }

    /** The address with its prefix, as an operator would write it. */
    public function cidr(): string
    {
        return $this->prefix_len === null ? $this->ip : "{$this->ip}/{$this->prefix_len}";
    }
}
