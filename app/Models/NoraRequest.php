<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email to Lumen NORA about a circuit. See the migration for why these are kept. */
class NoraRequest extends Model
{
    protected $fillable = [
        'circuit_id', 'circuit_isp_ticket_id', 'kind', 'message_id', 'subject', 'body',
        'impact', 'sent_by', 'sent_at', 'ticket_number', 'replied_at', 'reply_excerpt',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }
}
