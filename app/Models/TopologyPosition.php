<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopologyPosition extends Model
{
    protected $fillable = ['site_id', 'node_id', 'x', 'y'];

    protected $casts = ['x' => 'float', 'y' => 'float'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
