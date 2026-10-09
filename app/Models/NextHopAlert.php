<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Observers\NextHopAlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([NextHopAlertObserver::class])]
class NextHopAlert extends Model
{
    use HasFactory;

    /**
     * How recently the next-hop must have been read for an open alert to still count.
     * The SSH sweep runs every 5 minutes and takes a few; 30 is generous enough that a
     * slow sweep never drops a live incident, tight enough that a broken one cannot
     * hold a false alarm open for days.
     */
    public const EVIDENCE_MINUTES = 30;

    protected $fillable = [
        'device_id',
        'device_next_hop_id',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function nextHop(): BelongsTo
    {
        return $this->belongsTo(DeviceNextHop::class, 'device_next_hop_id');
    }

    /**
     * Open alerts that current evidence still supports.
     *
     * An open row is NOT proof a gateway is down — it only records that one was, once.
     * The poller closes it on recovery, but it can only do that for an appliance it
     * reached: `show system nexthops` returning nothing (SSH refused, credentials
     * rotated, a replacement appliance whose output does not parse) makes NextHopPoller
     * return before the close, and the alert stays open for as long as SSH stays broken.
     * During an SD-WAN migration that is every appliance being swapped.
     *
     * So the alert is cross-checked against the next-hop it names, every time it is read:
     *  - the hop still reads DOWN, and was checked recently  → a real incident
     *  - the hop reads UP                                    → the close was missed
     *  - the hop row is gone                                 → orphaned by a prune
     *  - the hop has not been checked in a long time         → we do not know, and a
     *    guess is not an incident; a genuinely dark site raises its own device-down
     *    and SNMP WAN alarms, which do not depend on SSH
     */
    public function scopeStillFailing($query)
    {
        return $query->whereNull('ended_at')->whereHas('nextHop', fn ($q) => $q
            ->where('status', 'down')
            ->where('last_checked_at', '>=', now()->subMinutes(self::EVIDENCE_MINUTES)));
    }
}
