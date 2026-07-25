<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use App\Observers\InterfaceAlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([InterfaceAlertObserver::class])]
class InterfaceAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_interface_id',
        'severity',
        'ticket_number',
        'started_at',
        'ended_at',
        'acknowledged_at',
        'acknowledged_by',
        'ack_note',
        'cleared_by',
        'clear_note',
        'cleared_manually',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'cleared_manually' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Assign an 8-digit tracking ticket the first time an alert is opened,
        // matching the device-alarm workflow.
        static::creating(function (InterfaceAlert $alert) {
            if (! $alert->ticket_number) {
                $alert->ticket_number = self::generateTicketNumber();
            }
        });
    }

    public static function generateTicketNumber(): string
    {
        do {
            $ticket = (string) random_int(10_000_000, 99_999_999);
        } while (self::where('ticket_number', $ticket)->exists());

        return $ticket;
    }

    public function deviceInterface(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class);
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
