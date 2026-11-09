<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sampled flow (a conversation) as decoded from NetFlow/sFlow by goflow2. */
class FlowRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'if_index', 'src_ip', 'dst_ip', 'src_port', 'dst_port',
        'protocol', 'app', 'app_category', 'direction', 'bytes', 'packets',
        'flow_start', 'recorded_at',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'packets' => 'integer',
        'flow_start' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
