<?php

namespace App\Models;

use App\Services\Ipam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A whole subnet held for a site that has not been built yet.
 *
 * IpReservation records ONE address that exists. This records a BLOCK that is spoken
 * for and deliberately empty. See the migration for why the distinction earns a table.
 */
class SubnetReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'cidr', 'site_id', 'site_label', 'label',
        'planned_for', 'released_at', 'released_by', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_for' => 'date',
            'released_at' => 'datetime',
        ];
    }

    /** Holds still in force. A released block is history, not an allocation. */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Whoever this is for, in the plainest words available. */
    public function getHolderAttribute(): ?string
    {
        return $this->site?->name ?: $this->site_label;
    }

    /**
     * A hold whose planned date has passed and that nobody released.
     *
     * Not an error — deployments slip. It is the prompt to ask whether the block is
     * still needed, which is the question an untouched reservation never raises by
     * itself. That is how reserved space quietly becomes unusable space.
     */
    public function getOverdueAttribute(): bool
    {
        return $this->released_at === null
            && $this->planned_for !== null
            && $this->planned_for->isPast();
    }

    /** Does this hold cover the given address? */
    public function covers(string $ip): bool
    {
        return Ipam::inside($ip, $this->cidr);
    }
}
