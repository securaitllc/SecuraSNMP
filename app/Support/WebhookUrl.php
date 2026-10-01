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

        // An address does not have to be written as a dotted quad to be one. inet_aton
        // — which is what curl ends up using — also accepts hex (0x7f000001), octal
        // (0177.0.0.1) and short forms (127.1), and every one of them is 127.0.0.1.
        // FILTER_VALIDATE_IP rejects all of them as IPs, so they fell through to the
        // hostname path, failed to resolve, and were waved through as "not an SSRF
        // target" — while the HTTP client went straight to loopback. Canonicalise to
        // the address the client will actually dial, then judge that.
        $numeric = self::numericHostToIp($host);

        if ($numeric !== null) {
            return ! self::isReserved($numeric);
        }

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

    /**
     * The IPv4 address a host string denotes, if it denotes one at all.
     *
     * Follows inet_aton: one to four parts, each decimal, octal (leading 0) or hex
     * (leading 0x), with the final part absorbing the remaining low bytes. Returns
     * null for anything that is a real hostname ("1e100.net" is not a number), which
     * sends it down the DNS path instead.
     */
    private static function numericHostToIp(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^0[xX][0-9a-fA-F]{1,8}$/', $part)) {
                $values[] = hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]{1,11}$/', $part)) {
                $values[] = octdec($part);
            } elseif (preg_match('/^(0|[1-9][0-9]{0,9})$/', $part)) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }

        $last = array_pop($values);

        // The trailing part carries every byte the earlier parts did not name:
        // "127.1" is 127.0.0.1, so its last part may be up to 2^24-1.
        if ($last < 0 || $last >= 2 ** (8 * (4 - count($values)))) {
            return null;
        }

        $address = $last;

        foreach ($values as $index => $value) {
            if ($value > 255) {
                return null;
            }

            $address |= $value << (8 * (3 - $index));
        }

        return long2ip($address);
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
