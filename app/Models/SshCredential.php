<?php

namespace App\Models;

use App\Casts\SafeEncrypted;

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
        'password' => SafeEncrypted::class,
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
