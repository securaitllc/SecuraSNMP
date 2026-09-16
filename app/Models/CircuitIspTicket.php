<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ISP ticket raised on a circuit — the trail behind circuits.isp_ticket.
 *
 * The circuit carries the CURRENT number because that is what every live screen reads.
 * This carries the history: what was raised, by whom, for what, and when it closed.
 */
class CircuitIspTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'circuit_id', 'ticket_number', 'reason', 'anomaly_id',
        'opened_at', 'opened_by', 'closed_at', 'closed_by', 'note',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
