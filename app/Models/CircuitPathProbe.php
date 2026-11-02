<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hop-by-hop probe of a circuit's path. See the migration for why these are kept.
 */
class CircuitPathProbe extends Model
{
    protected $fillable = [
        'circuit_id', 'target', 'trigger', 'tool', 'cycles', 'hops',
        'worst_hop', 'worst_hop_host', 'worst_hop_loss_pct', 'end_loss_pct',
        'summary', 'ran_by', 'ran_at',
    ];

    protected $casts = [
        'hops' => 'array',
        'ran_at' => 'datetime',
    ];

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }
}
