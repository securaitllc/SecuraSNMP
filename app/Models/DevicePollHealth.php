<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One poller's ability to reach one device. See the migration for why this exists.
 */
class DevicePollHealth extends Model
{
    use HasFactory;

    protected $table = 'device_poll_health';

    /** The transports a device is polled over. */
    public const TRANSPORT_SSH = 'ssh';

    public const TRANSPORT_SNMP = 'snmp';

    public const TRANSPORTS = [self::TRANSPORT_SSH, self::TRANSPORT_SNMP];

    /** Must fit `transport` varchar(16) — MySQL rejects an overflow, SQLite does not. */
    public const TRANSPORT_MAX = 16;

    protected $fillable = [
        'device_id', 'transport', 'last_success_at', 'last_failure_at',
        'consecutive_failures', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Rows whose last success is older than $minutes — or that have never succeeded
     * at all, provided the row itself is old enough to have had a fair chance.
     *
     * A device added minutes ago has no success yet and is not stale; it is simply
     * new. Treating those two states alike would alarm on every fresh device.
     */
    public function scopeStale(Builder $query, int $minutes): Builder
    {
        $cut = now()->subMinutes($minutes);

        return $query->where(function (Builder $q) use ($cut) {
            $q->where('last_success_at', '<', $cut)
                ->orWhere(fn (Builder $n) => $n->whereNull('last_success_at')->where('created_at', '<', $cut));
        });
    }

    /** How long since this poller last got in, in minutes. Null when it never has. */
    public function getStaleMinutesAttribute(): ?int
    {
        if ($this->last_success_at === null) {
            // Never succeeded: measure from when we first tried, which is the row.
            return $this->created_at?->diffInMinutes(now());
        }

        // Diff FORWARD from the earlier instant. Carbon 3 returns a SIGNED value, so
        // now()->diffInMinutes($past) is negative — a mistake this codebase has
        // shipped three times.
        return (int) $this->last_success_at->diffInMinutes(now());
    }
}
