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
        'subnet',
        'latitude',
        'longitude',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

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
}
