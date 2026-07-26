<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_channel_id',
        'event',
        'severity',
        'subject',
        'body',
        'status',
        'error',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }
}
