<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopologyPosition extends Model
{
    /** The layout generation positions are meaningful for — see the migration. */
    public const LAYOUT = 'v1';

    protected $fillable = ['site_id', 'node_id', 'layout', 'x', 'y'];

    protected $casts = ['x' => 'float', 'y' => 'float'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
