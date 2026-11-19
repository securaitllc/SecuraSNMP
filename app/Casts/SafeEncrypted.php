<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Like Laravel's built-in 'encrypted' cast, but it NEVER throws while reading.
 *
 * The stock cast calls Crypt::decrypt() and lets a DecryptException("The payload is
 * invalid") bubble up. That turns any row whose column is not a valid ciphertext for
 * the current APP_KEY into a hard 500 on every endpoint that serialises the model —
 * and one such row is enough to break the whole list. This bit Massey production:
 * device credentials stored as plaintext before the encrypted cast was introduced
 * (and/or written under a different APP_KEY) made GET /api/devices return 500.
 *
 * Behaviour:
 *   - read  → decrypt; on failure return the RAW stored value, so legacy plaintext
 *             (e.g. an SNMP community "public") stays usable instead of vanishing or
 *             crashing. Truly unrecoverable data (old-key ciphertext) reads back as
 *             its raw bytes — harmless, never a 500, and re-enterable by an admin.
 *   - write → always encrypt with the current key, so any legacy row self-heals into
 *             a proper ciphertext the next time the model is saved.
 */
class SafeEncrypted implements CastsAttributes
{
    /** @var array<string, true> per-process de-dupe so one bad row logs once, not per read. */
    private static array $warned = [];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Legacy plaintext or key-mismatch: return as-is rather than crash — but
            // SURFACE it (metadata only, NEVER the value). Silent swallowing hid the
            // fact that pre-cast prod rows were still plaintext at rest. De-duped per
            // (model,id,column) per process so a serialised list doesn't spam the log.
            // Run `php artisan secrets:reencrypt` to fix + `--verify` to confirm zero.
            $sig = get_class($model).':'.$model->getKey().':'.$key;
            if (! isset(self::$warned[$sig])) {
                self::$warned[$sig] = true;
                Log::warning('SafeEncrypted: unreadable secret column at rest (plaintext or key mismatch)', [
                    'model' => get_class($model),
                    'id' => $model->getKey(),
                    'column' => $key,
                ]);
            }

            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
