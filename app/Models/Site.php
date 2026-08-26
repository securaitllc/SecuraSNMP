<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'site_number',
        'region',
        'main_phone',
        'fax',
        'gm_name',
        'gm_phone',
        'gm_ext',
        'om_name',
        'om_phone',
        'om_ext',
        'sm_name',
        'sm_phone',
        'sm_ext',
        'site_type',
        'hub_site_id',
        'address',
        'occupancy',
        'lease_end_date',
        'lease_notes',
        'subnet',
        'latitude',
        'longitude',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'lease_end_date' => 'date',
    ];

    /** Lease posture travels with every site payload so the UI never recomputes it. */
    protected $appends = ['lease_days_left', 'lease_status'];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function circuits(): HasMany
    {
        return $this->hasMany(Circuit::class);
    }

    /** The hub this branch homes to (null for hubs / unassigned branches). */
    public function hub(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'hub_site_id');
    }

    /** Branches that home to this hub. */
    public function branches(): HasMany
    {
        return $this->hasMany(Site::class, 'hub_site_id');
    }

    public function isHub(): bool
    {
        return $this->site_type === 'hub';
    }

    /** The hubs this branch homes to (many-to-many). */
    public function hubs(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_hub', 'site_id', 'hub_site_id');
    }

    /** The branches that home to this hub. */
    public function spokes(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_hub', 'hub_site_id', 'site_id');
    }

    /** Circuits shared TO this site from another site (e.g. a CORP LAB uplink). */
    public function sharedCircuits(): BelongsToMany
    {
        return $this->belongsToMany(Circuit::class, 'circuit_site', 'site_id', 'circuit_id');
    }

    /** True when Massey owns the building — there is no lease to run out. */
    public function isOwned(): bool
    {
        return $this->occupancy === 'owned';
    }

    /** Days until the lease ends (negative once lapsed); null when owned/unrecorded. */
    public function daysToLeaseEnd(): ?int
    {
        return $this->lease_end_date && ! $this->isOwned()
            ? (int) now()->startOfDay()->diffInDays($this->lease_end_date->startOfDay(), false)
            : null;
    }

    /**
     * Lease lifecycle bucket. Deliberately WIDER than the circuit-contract windows
     * (30/60d): a lease decision — renew, extend, or plan a move — is made seasons
     * ahead, and it has to be made before an ISP contract can be signed against it.
     * owned (nothing to track), none (not recorded yet), expired, ≤90d, ≤180d, ok.
     */
    public function leaseStatus(): string
    {
        if ($this->isOwned()) {
            return 'owned';
        }
        $d = $this->daysToLeaseEnd();
        if ($d === null) {
            return 'none';
        }
        if ($d < 0) {
            return 'expired';
        }
        if ($d <= 90) {
            return 'warning';
        }
        if ($d <= 180) {
            return 'notice';
        }

        return 'ok';
    }

    public function getLeaseDaysLeftAttribute(): ?int
    {
        return $this->daysToLeaseEnd();
    }

    public function getLeaseStatusAttribute(): string
    {
        return $this->leaseStatus();
    }

    /** Leases ending within $days (or already lapsed). Owned sites never match. */
    public function scopeLeaseEndingWithin($query, int $days)
    {
        return $query->where('occupancy', '!=', 'owned')
            ->whereNotNull('lease_end_date')
            ->whereDate('lease_end_date', '<=', now()->addDays($days));
    }
}
