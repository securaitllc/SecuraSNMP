<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SshCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'username',
        'password',
        'notes',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
