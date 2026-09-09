<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterfaceMetricHistory extends Model
{
    protected $table = 'interface_metric_history';

    protected $fillable = [
        'device_interface_id',
        'recorded_at',
        'status',
        'in_octets_delta',
        'out_octets_delta',
        'in_discards_delta',
        'out_discards_delta',
        'in_errors_delta',
        'out_errors_delta',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function deviceInterface(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class);
    }
}
