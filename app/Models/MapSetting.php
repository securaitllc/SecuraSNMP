<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton basemap settings row (id = 1). The tile-provider key is encrypted at rest.
 *
 * CARTO watermarks tiles fetched without a key, so this is what keeps the dashboard
 * map clean. A wrong key is not fatal — CARTO still returns a tile, just the
 * watermarked one — so a bad value degrades to today's behaviour instead of a blank map.
 */
class MapSetting extends Model
{
    protected $fillable = ['provider', 'api_key'];

    protected $casts = [
        'api_key' => SafeEncrypted::class,   // AES at rest, same store as SNMP creds
    ];

    /** Never serialize the decrypted key — the API returns a masked hint instead. */
    protected $hidden = ['api_key'];

    /** The one settings row, created empty on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['provider' => 'carto']);
    }

    /**
     * Configured key, or null when unset — the proxy then fetches keyless (watermarked).
     *
     * Memoised for a minute because the tile proxy is the highest-volume endpoint in
     * the app: without this every single tile would cost a database round trip. And
     * it never throws — if the DB is unreachable (or the table has not been migrated
     * yet) the map degrades to watermarked tiles instead of failing to draw at all.
     */
    public static function tileKey(): ?string
    {
        return Cache::remember('mapsetting:tilekey', now()->addMinute(), function () {
            try {
                $row = static::find(1);

                return $row && filled($row->api_key) ? $row->api_key : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** Drop the memoised key so a save takes effect on the very next tile. */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('mapsetting:tilekey'));
        static::deleted(fn () => Cache::forget('mapsetting:tilekey'));
    }

    /**
     * Short fingerprint of the active key, used to namespace the tile cache.
     *
     * Tiles are cached for 30 days, so changing the key must not leave weeks of
     * watermarked tiles behind. Folding the fingerprint into the cache key means a
     * new key transparently reads from a fresh namespace — no manual flush, and no
     * risk of serving one key's tiles after it has been replaced.
     */
    public static function cacheNamespace(): string
    {
        $k = static::tileKey();

        return $k ? substr(hash('sha256', $k), 0, 8) : 'nokey';
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
