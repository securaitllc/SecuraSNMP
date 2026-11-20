<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator-recorded subnet mask, overriding the /24 that LAN discovery assumes.
 *
 * ARP says who answered, never what the mask is. Everything else in IPAM is observed;
 * this is the one thing that cannot be, so it is written down. See the migration for
 * why (10.11.0.0 is a /23 and was being reported as two /24s).
 */
class IpPrefix extends Model
{
    use HasFactory;

    protected $fillable = ['cidr', 'site_id', 'label', 'note', 'created_by'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
