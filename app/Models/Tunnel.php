<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tunnel extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'tunnel_name',
        'peer',
        'hub',
        'status',
        'in_discards',
        'out_discards',
        'in_discards_delta',
        'out_discards_delta',
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
        return $this->hasMany(TunnelAlert::class);
    }

    /**
     * The most recent alert (open or closed) — lets a consumer tell a NOC-cleared
     * tunnel apart from a currently-alarming one, the same way circuits do.
     */
    public function latestAlert(): HasOne
    {
        return $this->hasOne(TunnelAlert::class)->latestOfMany('started_at');
    }

    public function metricHistory(): HasMany
    {
        return $this->hasMany(TunnelMetricHistory::class);
    }
}
