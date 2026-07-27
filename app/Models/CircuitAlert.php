<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Observers\CircuitAlertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CircuitAlertObserver::class])]
class CircuitAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'circuit_id',
        'started_at',
        'ended_at',
        'cause',
        'detected_loss_pct',
        'ticket_number',
        'acknowledged_at',
        'acknowledged_by',
        'ack_note',
        'cleared_by',
        'clear_note',
        'cleared_manually',
        'dispatch_at',
        'dispatch_note',
        'dispatch_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'cleared_manually' => 'boolean',
        'dispatch_at' => 'datetime',
    ];

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function dispatchBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatch_by');
    }
}
