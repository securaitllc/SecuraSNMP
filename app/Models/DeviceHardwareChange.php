<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to what a device is — its serial, name, model or OS.
 *
 * Append-only by intent: nothing in the app updates or deletes a row here, because
 * the value of the trail is that it is complete.
 */
class DeviceHardwareChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'field', 'old_value', 'new_value',
        'source', 'user_id', 'user_name', 'detected_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Record a change, unless nothing actually changed.
     *
     * A first observation (no old value) is not a change — writing one would stamp
     * every device on the day the poller first read it and bury the real swaps.
     */
    public static function record(
        Device $device,
        string $field,
        ?string $old,
        ?string $new,
        string $source = 'snmp',
        ?User $user = null,
    ): ?self {
        $old = trim((string) $old);
        $new = trim((string) $new);

        if ($old === '' || $new === '' || strcasecmp($old, $new) === 0) {
            return null;
        }

        return self::create([
            'device_id' => $device->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'source' => $source,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'detected_at' => now(),
        ]);
    }
}
