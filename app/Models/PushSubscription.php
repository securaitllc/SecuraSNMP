<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone or browser that asked to be woken for alarms (Web Push). */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth', 'user_agent', 'min_severity', 'last_sent_at', 'failures'];

    /** Keys never leave the server — the client only ever sees its own endpoint. */
    protected $hidden = ['p256dh', 'auth'];

    protected $casts = ['last_sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
