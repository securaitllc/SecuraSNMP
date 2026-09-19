<?php

namespace App\Models;

use App\Casts\SafeEncrypted;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton SMTP settings row (id = 1). Password is encrypted at rest.
 */
class MailSetting extends Model
{
    protected $fillable = [
        'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'enabled',
    ];

    protected $casts = [
        'port' => 'integer',
        'password' => SafeEncrypted::class,
        'enabled' => 'boolean',
    ];

    /** The one settings row, created empty on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
