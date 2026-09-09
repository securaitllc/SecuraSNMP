<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;

class OsintIntegration extends Model
{
    protected $fillable = ['provider', 'api_key', 'meta', 'enabled', 'updated_by'];

    protected $casts = [
        'api_key' => SafeEncrypted::class,   // AES at rest, same store as SNMP creds
        'meta' => 'array',
        'enabled' => 'boolean',
    ];

    /** Never serialize the decrypted key to JSON — the API returns a masked hint instead. */
    protected $hidden = ['api_key'];

    /** Decrypted key for a provider, or null when not configured / disabled. */
    public static function keyFor(string $provider): ?string
    {
        $row = static::where('provider', $provider)->where('enabled', true)->first();

        return $row && filled($row->api_key) ? $row->api_key : null;
    }

    public function hasKey(): bool
    {
        return filled($this->api_key);
    }

    /** Last 4 of the key for the UI, e.g. "••••3f9a" — enough to confirm which key is saved. */
    public function maskedKey(): ?string
    {
        $k = $this->api_key;

        return $k ? '••••••••'.substr($k, -4) : null;
    }
}
