<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpReservation extends Model
{
    use HasFactory;

    /** What an address is being used for. A VIP or NAT pool is invisible to SNMP. */
    public const PURPOSES = ['vip', 'nat', 'host', 'gateway', 'reserved'];

    public const ASSIGNMENTS = ['static', 'dhcp'];

    protected $fillable = [
        'ip', 'prefix_len', 'site_id', 'device_id',
        'label', 'purpose', 'assignment', 'note', 'created_by',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
