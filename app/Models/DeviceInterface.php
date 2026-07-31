<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceInterface extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'if_index',
        'if_name',
        'status',
        'admin_status',
        'alarm_suppressed',
        'in_octets',
        'out_octets',
        'in_discards',
        'out_discards',
        'in_discards_delta',
        'out_discards_delta',
        'in_errors',
        'out_errors',
        'in_errors_delta',
        'out_errors_delta',
        'speed_bps',
        'in_util_pct',
        'out_util_pct',
        'last_polled_at',
        'note',
        'note_by',
        'note_at',
        'health_ack_at',
        'health_ack_by',
        'flap_count',
        'last_flap_at',
        'last_error_at',
        'last_discard_at',
    ];

    protected $casts = [
        'last_polled_at' => 'datetime',
        'in_util_pct' => 'float',
        'out_util_pct' => 'float',
        'speed_bps' => 'integer',
        'alarm_suppressed' => 'boolean',
        'note_at' => 'datetime',
        'health_ack_at' => 'datetime',
        'flap_count' => 'integer',
        'last_flap_at' => 'datetime',
        'last_error_at' => 'datetime',
        'last_discard_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(InterfaceAlert::class);
    }

    public function metricHistory(): HasMany
    {
        return $this->hasMany(InterfaceMetricHistory::class);
    }
}
