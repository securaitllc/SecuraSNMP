<?php

namespace App\Models;

use App\Support\TicketNumber;
use App\Observers\DeviceAlarmObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([DeviceAlarmObserver::class])]
class DeviceAlarm extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'alarm_id',
        'ticket_number',
        'legacy_ticket_number',
        'description',
        'severity',
        'acknowledged_at',
        'acknowledged_by',
        'ack_note',
        'first_seen_at',
        'cleared_at',
        'cleared_by',
        'clear_note',
        'cleared_manually',
        'active_on_device',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'cleared_at' => 'datetime',
        'cleared_manually' => 'boolean',
        'active_on_device' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Assign an 8-digit tracking ticket the first time an alarm is created.
        static::creating(function (DeviceAlarm $alarm) {
            if (! $alarm->ticket_number) {
                $alarm->ticket_number = self::generateTicketNumber();
            }
        });
    }

    /** A unique 8-digit ticket number (10000000–99999999). */
    /** Sequential, e.g. MSIT-ALM-000123. See TicketNumber for why not random. */
    public static function generateTicketNumber(): string
    {
        return TicketNumber::next(TicketNumber::TYPE_ALARM);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }
}
