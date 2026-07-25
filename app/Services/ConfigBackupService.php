<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceConfig;

/**
 * Captures device running-configs over SSH and versions them. A new version is
 * only stored when the config actually changed (hash differs from the latest),
 * and a change on a device that already had a backup fires a drift notification.
 *
 * The SSH exec is injected as a callable so the versioning/drift logic is
 * deterministic and unit-testable (same pattern as SshVerifier).
 */
class ConfigBackupService
{
    /** Vendor -> command that prints the full config. */
    private const COMMANDS = [
        'juniper' => 'show configuration | no-more',
        'fortigate' => 'show full-configuration',
        'silverpeak' => 'show running-config',
    ];

    /** @param callable(Device, string): string $executor Runs a command over SSH, returns stdout. */
    public function __construct(private $executor)
    {
    }

    public static function forProduction(): self
    {
        // Interactive-shell exec (see SshSession) — network CLIs reject the SSH
        // exec channel used previously.
        return new self(function (Device $device, string $command): string {
            $output = \App\Support\SshSession::run($device, [$command]);

            return $output[$command] ?? '';
        });
    }

    public static function commandFor(Device $device): string
    {
        return self::COMMANDS[$device->vendor] ?? 'show running-config';
    }

    private const REDACTED = '<redacted>';

    /**
     * Mask every credential in a device config while preserving structure so a
     * diff still shows non-secret changes. Covers Junos/IOS ("keyword value"),
     * FortiGate ("set <field> ...") and any unix-crypt hash token, across the
     * common secret directives. Redaction is deterministic, so a password-only
     * change does not create false drift (and never leaks the changed secret).
     */
    public static function redactSecrets(string $content): string
    {
        // A secret directive: everything after the keyword to end-of-line is the
        // credential (possibly with a modifier like "ascii-text"/"ENC" in front),
        // so mask all of it — keeping any trailing Junos ';' or '{' so the block
        // structure (and the diff) stays intact.
        $keyword = '/\b(encrypted-password|simple-password|authentication-key|hello-authentication-key|'
            .'pre-shared-key|password|passwd|psksecret|ppk-secret|auth-pwd|priv-pwd|private-key|passphrase|'
            .'secret|community|key-string|md5-key|md5)\b\s+.+?(\s*[;{]?)\s*$/i';

        // Any leftover unix-crypt / MD5 / bcrypt hash token ($1$, $5$, $6$, $9$, $2a$…).
        $hash = '/\$[0-9a-z]{1,2}\$[^\s"]+/i';

        $lines = array_map(function (string $line) use ($keyword, $hash) {
            $line = preg_replace($keyword, '$1 '.self::REDACTED.'$2', $line);

            return preg_replace($hash, self::REDACTED, $line);
        }, explode("\n", $content));

        return implode("\n", $lines);
    }

    /**
     * Capture and version a device's config. Returns the new DeviceConfig when
     * the config changed (or is the first capture), or null when unchanged.
     */
    public function backup(Device $device): ?DeviceConfig
    {
        $content = trim(($this->executor)($device, self::commandFor($device)));

        if ($content === '') {
            return null;
        }

        // Strip every credential BEFORE hashing or storing, so no password hash,
        // key, shared secret or SNMP community is ever written to the database or
        // shown in the config viewer/diff — even though the store is encrypted.
        $content = self::redactSecrets($content);

        $hash = hash('sha256', $content);
        $latest = DeviceConfig::where('device_id', $device->id)->latest('captured_at')->first();

        if ($latest && $latest->hash === $hash) {
            return null; // no drift
        }

        $config = DeviceConfig::create([
            'device_id' => $device->id,
            'content' => $content,
            'hash' => $hash,
            'line_count' => substr_count($content, "\n") + 1,
            'captured_at' => now(),
        ]);

        // A change against an existing baseline is drift — notify.
        if ($latest) {
            AlertNotifier::dispatch(
                'open',
                'warning',
                "Config changed on {$device->name}",
                "The running configuration of {$device->name} ({$device->ip_address}) changed.",
                ['type' => 'config_drift', 'device_id' => $device->id, 'config_id' => $config->id],
                $device->id,
                $device->site_id,
            );
        }

        return $config;
    }
}
