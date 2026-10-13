<?php

namespace App\Support;

/**
 * MAC → manufacturer, from the bundled IEEE OUI registry (offline — no external
 * calls, so it works on the isolated NOC network). The map (~40k prefixes) loads
 * once per process.
 */
class MacOui
{
    private static ?array $map = null;

    private static function map(): array
    {
        if (self::$map === null) {
            $path = resource_path('data/oui.json');
            self::$map = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }

        return self::$map;
    }

    /** Vendor for a MAC (any separator/case), or null if the OUI isn't registered. */
    public static function vendor(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));
        if (strlen($hex) < 6) {
            return null;
        }

        return self::map()[substr($hex, 0, 6)] ?? null;
    }

    /** Canonical AA:BB:CC:DD:EE:FF, or the trimmed input if it isn't 12 hex digits. */
    public static function normalize(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));
        if (strlen($hex) !== 12) {
            return $mac ? strtoupper(trim($mac)) : null;
        }

        return implode(':', str_split($hex, 2));
    }
}
