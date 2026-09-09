<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'site_id',
        'device_id',
        'reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Currently-active windows. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }

    /**
     * Active windows covering each of the given devices, keyed by device id.
     *
     * A window can be scoped to the device, to its site, or be global — all three
     * cover the device, and availability reporting has to subtract every one of them.
     *
     * @param  \Illuminate\Support\Collection<int, Device>  $devices
     * @return array<int, array<int, array{starts_at: \Illuminate\Support\Carbon, ends_at: \Illuminate\Support\Carbon}>>
     */
    public static function spansForDevices($devices, $from, $to): array
    {
        $windows = static::query()
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->get(['site_id', 'device_id', 'starts_at', 'ends_at']);

        if ($windows->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($devices as $device) {
            foreach ($windows as $w) {
                $covers = ($w->site_id === null && $w->device_id === null)
                    || ($w->device_id !== null && $w->device_id === $device->id)
                    || ($w->site_id !== null && $w->site_id === $device->site_id);

                if ($covers) {
                    $out[$device->id][] = ['starts_at' => $w->starts_at, 'ends_at' => $w->ends_at];
                }
            }
        }

        return $out;
    }

    /**
     * True when an active window suppresses notifications for the given scope:
     * a global window, or one matching the device or its site.
     */
    public static function suppresses(?int $deviceId, ?int $siteId): bool
    {
        return static::active()
            ->where(function (Builder $q) use ($deviceId, $siteId): void {
                $q->where(fn (Builder $g) => $g->whereNull('site_id')->whereNull('device_id')); // global
                if ($deviceId) {
                    $q->orWhere('device_id', $deviceId);
                }
                if ($siteId) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->exists();
    }
}
