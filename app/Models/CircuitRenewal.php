<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded contract renewal — the accountability trail. Each row keeps the
 * old and new end dates, the term, a note, and who logged it, so a renewal is
 * never a silent date overwrite.
 */
class CircuitRenewal extends Model
{
    use HasFactory;

    protected $fillable = [
        'circuit_id',
        'previous_end_date',
        'new_end_date',
        'term_months',
        'note',
        'renewed_by',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'new_end_date' => 'date',
    ];

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }

    public function renewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renewed_by');
    }
}
