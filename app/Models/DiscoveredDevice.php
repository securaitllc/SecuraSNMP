<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveredDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'discovery_scan_id',
        'ip_address',
        'sys_name',
        'sys_descr',
        'sys_object_id',
        'vendor',
        'model',
        'serial_number',
        'suggested_role',
        'suggested_site_id',
        'matched_device_id',
        'imported_device_id',
        'status',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DiscoveryScan::class, 'discovery_scan_id');
    }

    public function suggestedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'suggested_site_id');
    }

    public function matchedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'matched_device_id');
    }
}
