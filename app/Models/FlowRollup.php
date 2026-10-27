<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hourly/daily aggregate of flows, keyed per talker or per app, for the long view. */
class FlowRollup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'if_index', 'bucket', 'bucket_start', 'group_type',
        'group_key', 'sub_key', 'app_category', 'bytes', 'packets', 'flows',
    ];

    protected $casts = [
        'bucket_start' => 'datetime',
        'bytes' => 'integer',
        'packets' => 'integer',
        'flows' => 'integer',
    ];
}
