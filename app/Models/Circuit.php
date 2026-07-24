<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Circuit extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'isp_provider_id',
        'isp_name',
        'circuit_type',
        'ip_assignment',
        'monitor_via',
        'wan_interface',
        'ping_target',
        'circuit_id',
        'account_number',
        'support_phone',
        'monitored_ip',
        'subnet',
        'gateway_ip',
        'lec_name',
        'lec_circuit_id',
        'lec_support_phone',
        'notes',
        'status',
        'monitoring_enabled',
        'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'monitoring_enabled' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Additional sites this circuit serves (beyond its owner site). */
    public function sharedSites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'circuit_site', 'circuit_id', 'site_id');
    }

    public function ispProvider(): BelongsTo
    {
        return $this->belongsTo(IspProvider::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(CircuitAlert::class);
    }

    public function latestAlert(): HasOne
    {
        return $this->hasOne(CircuitAlert::class)->latestOfMany('started_at');
    }

    public function metricHistory(): HasMany
    {
        return $this->hasMany(CircuitMetricHistory::class);
    }
}
