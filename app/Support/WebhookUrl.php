<?php

namespace App\Support;

/**
 * SSRF guard for admin-supplied webhook/Slack URLs.
 *
 * Blocks loopback, link-local (incl. the 169.254.169.254 cloud-metadata
 * endpoint) and other reserved ranges, so a channel cannot be pointed at the
 * host's own services or a cloud metadata API. Private LAN ranges (RFC1918) are
 * intentionally allowed — internal webhook receivers are a legitimate on-prem
 * NOC use case.
 */
class WebhookUrl
{
    public static function isSafe(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        $parts = parse_url($url);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        // Resolve BOTH A and AAAA so an IPv6-only rebind to ::1 / fe80:: can't slip
        // past an IPv4-only lookup. An unresolvable host resolves to nothing and is
        // allowed here — it is not an SSRF target (the connection simply fails);
        // the real protection is that AlertNotifier re-runs this check at send time
        // (and refuses redirects), so a host that *resolves to a reserved address*
        // at delivery is caught even if it looked fine when saved.
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : self::resolve($host);

        foreach ($ips as $ip) {
            if (self::isReserved($ip)) {
                return false;
            }
        }

        return true;
    }

    /** Resolve both A (IPv4) and AAAA (IPv6) records for a hostname. */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /** Reserved (loopback / link-local / unspecified / documentation). Private LAN is allowed. */
    private static function isReserved(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
