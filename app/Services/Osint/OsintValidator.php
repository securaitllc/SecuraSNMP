<?php

namespace App\Services\Osint;

use InvalidArgumentException;

/**
 * The security boundary for the OSINT tool. Every value that will reach a shell tool
 * (whois/dig/whatweb) or an upstream API is validated to a strict allow-list shape here
 * FIRST — so nothing containing a shell metacharacter, whitespace or control byte ever
 * gets that far. Process is still invoked with array args (never a shell string), so this
 * is defence-in-depth, not the only layer.
 */
class OsintValidator
{
    // RFC-1123 hostname: labels of a-z 0-9 and hyphens (not leading/trailing), TLD ≥2 alpha.
    private const DOMAIN_RE = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** Pull a bare hostname out of whatever the operator pasted (URL, host, host+path). */
    public static function normalizeHost(string $input): string
    {
        $s = trim(strtolower($input));
        $s = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $s); // strip scheme
        $s = explode('/', $s)[0];                              // drop path
        $s = explode('?', $s)[0];
        $s = explode('#', $s)[0];
        $s = explode('@', $s);                                 // drop any userinfo
        $s = end($s);
        $s = explode(':', $s)[0];                              // drop port

        return $s;
    }

    /** Validated bare domain/host, or throw. Safe to pass to Process array args. */
    public static function domain(string $input): string
    {
        $host = self::normalizeHost($input);
        if (! preg_match(self::DOMAIN_RE, $host)) {
            throw new InvalidArgumentException('Not a valid domain.');
        }

        return $host;
    }

    /** Registrable/base domain (last two labels) — what WHOIS + subdomain enum run on. */
    public static function baseDomain(string $input): string
    {
        $host = self::domain($input);
        $parts = explode('.', $host);

        return implode('.', array_slice($parts, -2));
    }

    public static function ip(string $input): string
    {
        $ip = trim($input);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Not a valid IP address.');
        }

        return $ip;
    }

    /** Normalise to E.164 digits (with leading +). Rejects anything non-numeric. */
    public static function phone(string $input): string
    {
        $raw = trim($input);
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw new InvalidArgumentException('Not a valid phone number.');
        }

        return ($plus ? '+' : '').$digits;
    }
}
