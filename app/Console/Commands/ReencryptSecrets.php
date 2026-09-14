<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts any secret column still stored as PLAINTEXT at rest.
 *
 * The SafeEncrypted cast only encrypts on write and decrypts-or-returns-raw on read,
 * so a row written before the cast existed (Massey prod did exactly this) stays
 * plaintext in MySQL — and in every backup — until it happens to be re-saved. Stable
 * devices are almost never edited, so those secrets sit unprotected indefinitely and
 * the cast hides it. This command force-encrypts them.
 *
 * Idempotent + safe: it reads each column's RAW stored value (bypassing the cast) and
 * only rewrites values that are NOT already valid current-key ciphertext — so a row
 * that's already encrypted is left untouched (no needless churn, no double-encryption).
 * A value that fails to decrypt is assumed plaintext and encrypted as-is.
 *
 *   php artisan secrets:reencrypt            # fix plaintext rows
 *   php artisan secrets:reencrypt --verify   # report only; exits non-zero if any remain
 */
class ReencryptSecrets extends Command
{
    protected $signature = 'secrets:reencrypt {--verify : Report unreadable columns without changing anything}';

    protected $description = 'Encrypt any secret column still stored as plaintext at rest (SafeEncrypted backfill).';

    /** table => [id column, [secret columns]] */
    private const TARGETS = [
        'devices' => ['snmp_community', 'snmp_v3_auth_key', 'snmp_v3_priv_key', 'ssh_credential'],
        'snmp_credentials' => ['snmp_community', 'snmp_v3_auth_key', 'snmp_v3_priv_key'],
        'ssh_credentials' => ['password'],
        'mail_settings' => ['password'],
    ];

    public function handle(): int
    {
        $verify = (bool) $this->option('verify');
        $fixed = 0;
        $unreadable = 0;   // still-not-ciphertext AFTER this run (verify) / before (fix)

        foreach (self::TARGETS as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $selectable = array_values(array_filter($columns, fn ($c) => DB::getSchemaBuilder()->hasColumn($table, $c)));
            if ($selectable === []) {
                continue;
            }

            DB::table($table)->select(array_merge(['id'], $selectable))->orderBy('id')
                ->chunk(200, function ($rows) use ($table, $selectable, $verify, &$fixed, &$unreadable) {
                    foreach ($rows as $row) {
                        foreach ($selectable as $col) {
                            $raw = $row->{$col};
                            if ($raw === null || $raw === '') {
                                continue;
                            }
                            // Already valid ciphertext → leave it alone.
                            try {
                                Crypt::decryptString($raw);

                                continue;
                            } catch (DecryptException) {
                                // Plaintext (or unrecoverable old-key ciphertext).
                            }

                            if ($verify) {
                                $unreadable++;
                                $this->line("  plaintext/unreadable: {$table}#{$row->id}.{$col}");

                                continue;
                            }

                            DB::table($table)->where('id', $row->id)->update([$col => Crypt::encryptString((string) $raw)]);
                            $fixed++;
                        }
                    }
                });
        }

        if ($verify) {
            if ($unreadable === 0) {
                $this->info('All secret columns are encrypted at rest. ✔');

                return self::SUCCESS;
            }
            $this->warn("{$unreadable} secret column(s) are NOT encrypted at rest — run `php artisan secrets:reencrypt` to fix.");

            return self::FAILURE;
        }

        $this->info("Encrypted {$fixed} plaintext secret column(s). Run with --verify to confirm zero remain.");

        return self::SUCCESS;
    }
}
