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
        'isp_ticket',
        'dispatch_at',
        'dispatch_end_at',
        'dispatch_note',
        'install_date',
        'contract_end_date',
        'contract_term_months',
        'status',
        'monitoring_enabled',
        'sla_target_pct',
        'contract_down_mbps',
        'contract_up_mbps',
        'last_loss_pct',
        'sustained_loss_pct',
        'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'dispatch_at' => 'datetime',
        'dispatch_end_at' => 'datetime',
        'install_date' => 'date',
        'contract_end_date' => 'date',
        'monitoring_enabled' => 'boolean',
        'sla_target_pct' => 'float',
        'contract_down_mbps' => 'integer',
        'contract_up_mbps' => 'integer',
    ];

    /** Days until the contract expires (negative = already expired), or null if no end date. */
    public function daysToExpiry(): ?int
    {
        return $this->contract_end_date
            ? (int) now()->startOfDay()->diffInDays($this->contract_end_date->startOfDay(), false)
            : null;
    }

    /**
     * Contract lifecycle bucket for reports/alerts. Not a network severity — an
     * ops reminder: expired (critical), ≤30d (warning), ≤60d (notice), else ok.
     */
    public function contractStatus(): string
    {
        $d = $this->daysToExpiry();
        if ($d === null) {
            return 'none';
        }
        if ($d < 0) {
            return 'expired';
        }
        if ($d <= 30) {
            return 'warning';
        }
        if ($d <= 60) {
            return 'notice';
        }

        return 'ok';
    }

    /** Circuits whose contract expires within $days (or is already expired). */
    public function scopeExpiringWithin($query, int $days)
    {
        return $query->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<=', now()->addDays($days));
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(CircuitRenewal::class)->latest('created_at');
    }

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
