<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'config',
        'min_severity',
        'enabled',
    ];

    protected $casts = [
        // Config (email address / webhook URL) is encrypted at rest.
        'config' => 'encrypted:array',
        'enabled' => 'boolean',
    ];
}
