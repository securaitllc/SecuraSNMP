<?php

namespace App\Models;

use App\Support\TicketNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use App\Observers\TunnelAlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([TunnelAlertObserver::class])]
class TunnelAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'tunnel_id',
        'ticket_number',
        'legacy_ticket_number',
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
        static::creating(function (TunnelAlert $alert) {
            if (! $alert->ticket_number) {
                $alert->ticket_number = self::generateTicketNumber();
            }
        });
    }

    /** Sequential, e.g. MSIT-TUN-000123. See TicketNumber for why not random. */
    public static function generateTicketNumber(): string
    {
        return TicketNumber::next(TicketNumber::TYPE_TUNNEL);
    }

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(Tunnel::class);
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
