<?php

namespace App\Services\Flow;

/**
 * Names the application behind a flow — the "DPI + IP intelligence" layer.
 *
 * Sampled flows carry no L7 payload, so classification is inference: the destination
 * IP against a catalog of known service networks (Microsoft 365, Google, etc.), then
 * the port/protocol. A starter catalog ships here; it's data, meant to grow (or be
 * fed by a maintained feed) without touching the logic. Pure + deterministic so it's
 * unit-testable and cheap to run on every ingested flow.
 */
class FlowClassifier
{
    /** IANA protocol number → name. */
    private const PROTOCOLS = [
        1 => 'icmp', 6 => 'tcp', 17 => 'udp', 47 => 'gre', 50 => 'esp',
        51 => 'ah', 58 => 'icmpv6', 89 => 'ospf', 132 => 'sctp',
    ];

    /** Known destination networks → [app, category]. Ordered most-specific-ish first. */
    private const NETWORKS = [
        // Microsoft 365 / Azure front-ends
        ['52.96.0.0/12', 'Microsoft 365', 'SaaS'],
        ['13.107.0.0/16', 'Microsoft 365', 'SaaS'],
        ['40.96.0.0/13', 'Microsoft 365', 'SaaS'],
        ['20.190.128.0/18', 'Microsoft 365', 'SaaS'],
        ['150.171.32.0/22', 'Microsoft Teams', 'Voice'],
        // Google / YouTube
        ['142.250.0.0/15', 'Google / YouTube', 'Streaming'],
        ['172.217.0.0/16', 'Google / YouTube', 'Streaming'],
        ['216.58.192.0/19', 'Google / YouTube', 'Streaming'],
        // Windows Update / MS content
        ['13.107.4.0/22', 'Windows Update', 'Updates'],
        // Common clouds (broad — a hint, not gospel)
        ['34.0.0.0/9', 'Google Cloud', 'SaaS'],
        ['35.190.0.0/17', 'Google Cloud', 'SaaS'],
        ['3.0.0.0/9', 'AWS', 'SaaS'],
        ['104.16.0.0/13', 'Cloudflare', 'Web'],
    ];

    /** Destination port → [app, category] when the IP catalog didn't match. */
    private const PORTS = [
        53 => ['DNS', 'Infrastructure'],
        123 => ['NTP', 'Infrastructure'],
        161 => ['SNMP', 'Infrastructure'],
        514 => ['Syslog', 'Infrastructure'],
        67 => ['DHCP', 'Infrastructure'],
        68 => ['DHCP', 'Infrastructure'],
        22 => ['SSH', 'Management'],
        23 => ['Telnet', 'Management'],
        3389 => ['RDP', 'Remote access'],
        5900 => ['VNC', 'Remote access'],
        25 => ['Email (SMTP)', 'Email'],
        587 => ['Email (SMTP)', 'Email'],
        465 => ['Email (SMTP)', 'Email'],
        993 => ['Email (IMAP)', 'Email'],
        995 => ['Email (POP3)', 'Email'],
        5060 => ['Voice (SIP)', 'Voice'],
        5061 => ['Voice (SIP)', 'Voice'],
        500 => ['VPN (IKE)', 'VPN'],
        4500 => ['VPN (IPsec)', 'VPN'],
        1194 => ['VPN (OpenVPN)', 'VPN'],
        443 => ['Web / TLS', 'Web'],
        80 => ['Web', 'Web'],
        8080 => ['Web', 'Web'],
    ];

    /** IANA protocol number → lowercase name (falls back to "proto-<n>"). */
    public function protocol(int $number): string
    {
        return self::PROTOCOLS[$number] ?? 'proto-'.$number;
    }

    /**
     * @return array{0: string, 1: string} [app, category]
     */
    public function classify(string $dstIp, string $srcIp, ?int $dstPort, ?int $srcPort, string $protocol): array
    {
        // IP intelligence first — a match on either end names the service.
        foreach ([$dstIp, $srcIp] as $ip) {
            foreach (self::NETWORKS as [$cidr, $app, $cat]) {
                if ($this->inCidr($ip, $cidr)) {
                    return [$app, $cat];
                }
            }
        }

        // ESP with no port is IPsec regardless of port.
        if ($protocol === 'esp') {
            return ['VPN (IPsec)', 'VPN'];
        }

        // Then the well-known port on either end (the smaller/"server" side usually).
        foreach ([$dstPort, $srcPort] as $port) {
            if ($port !== null && isset(self::PORTS[$port])) {
                return self::PORTS[$port];
            }
        }

        return ['Unclassified', 'Other'];
    }

    /** IPv4 CIDR membership (IPv6 is not matched by the starter catalog → false). */
    private function inCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/') || str_contains($ip, ':')) {
            return false;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits <= 0) {
            return true;
        }
        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($netLong & $mask);
    }
}
