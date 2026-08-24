<?php

namespace App\Models;

use App\Casts\SafeEncrypted;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SnmpCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'snmp_version',
        'snmp_community',
        'snmp_v3_username',
        'snmp_v3_auth_key',
        'snmp_v3_priv_key',
        'notes',
    ];

    protected $casts = [
        'snmp_community' => SafeEncrypted::class,
        'snmp_v3_auth_key' => SafeEncrypted::class,
        'snmp_v3_priv_key' => SafeEncrypted::class,
    ];

    public function scans(): HasMany
    {
        return $this->hasMany(DiscoveryScan::class);
    }
}
