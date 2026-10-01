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
        // CDNs (a lot of "Web" is really a CDN edge)
        ['23.0.0.0/12', 'Akamai CDN', 'Web'],
        ['23.192.0.0/11', 'Akamai CDN', 'Web'],
        ['184.24.0.0/13', 'Akamai CDN', 'Web'],
        ['104.64.0.0/10', 'Akamai CDN', 'Web'],
        ['151.101.0.0/16', 'Fastly CDN', 'Web'],
        ['199.232.0.0/16', 'Fastly CDN', 'Web'],
        ['104.16.0.0/13', 'Cloudflare', 'Web'],
        // Major SaaS / consumer clouds
        ['17.0.0.0/8', 'Apple', 'SaaS'],
        ['162.125.0.0/16', 'Dropbox', 'SaaS'],
        ['170.114.0.0/16', 'Zoom', 'Voice'],
        ['157.240.0.0/16', 'Meta / Facebook', 'Streaming'],
        ['31.13.24.0/21', 'Meta / Facebook', 'Streaming'],
        ['45.57.0.0/16', 'Netflix', 'Streaming'],
        // Common clouds (broad — a hint, not gospel)
        ['34.0.0.0/9', 'Google Cloud', 'SaaS'],
        ['35.190.0.0/17', 'Google Cloud', 'SaaS'],
        ['3.0.0.0/9', 'AWS', 'SaaS'],
    ];

    /**
     * Destination (or source) port → [app, category] when the IP catalog didn't match.
     * A broad well-known + common-enterprise catalog. Ordered loosely by category; PHP
     * array keys dedupe, so the last write wins if a port appears twice.
     */
    private const PORTS = [
        // --- Web ---
        80 => ['Web', 'Web'], 443 => ['Web / TLS', 'Web'], 8080 => ['Web', 'Web'],
        8443 => ['Web / TLS', 'Web'], 8000 => ['Web', 'Web'], 8008 => ['Web', 'Web'],
        8888 => ['Web', 'Web'], 591 => ['Web', 'Web'], 2052 => ['Web', 'Web'], 2082 => ['cPanel', 'Web'],
        2083 => ['cPanel', 'Web'], 2086 => ['WHM', 'Web'], 2087 => ['WHM', 'Web'], 2095 => ['Webmail', 'Web'],
        // --- DNS / DHCP / time / discovery ---
        53 => ['DNS', 'Infrastructure'], 853 => ['DNS over TLS', 'Infrastructure'],
        67 => ['DHCP', 'Infrastructure'], 68 => ['DHCP', 'Infrastructure'],
        546 => ['DHCPv6', 'Infrastructure'], 547 => ['DHCPv6', 'Infrastructure'],
        123 => ['NTP', 'Infrastructure'], 5353 => ['mDNS', 'Infrastructure'],
        1900 => ['SSDP / UPnP', 'Infrastructure'], 5355 => ['LLMNR', 'Infrastructure'],
        // --- Email ---
        25 => ['Email (SMTP)', 'Email'], 465 => ['Email (SMTP)', 'Email'], 587 => ['Email (SMTP)', 'Email'],
        2525 => ['Email (SMTP)', 'Email'], 110 => ['Email (POP3)', 'Email'], 995 => ['Email (POP3)', 'Email'],
        143 => ['Email (IMAP)', 'Email'], 993 => ['Email (IMAP)', 'Email'],
        // --- Remote access / management ---
        22 => ['SSH', 'Management'], 23 => ['Telnet', 'Management'], 992 => ['Telnet (TLS)', 'Management'],
        3389 => ['RDP', 'Remote access'], 5900 => ['VNC', 'Remote access'], 5901 => ['VNC', 'Remote access'],
        5902 => ['VNC', 'Remote access'], 5903 => ['VNC', 'Remote access'], 5938 => ['TeamViewer', 'Remote access'],
        3283 => ['Apple Remote Desktop', 'Remote access'], 5985 => ['WinRM', 'Management'], 5986 => ['WinRM', 'Management'],
        902 => ['VMware', 'Management'], 903 => ['VMware', 'Management'], 5480 => ['VMware VAMI', 'Management'],
        // --- Windows / Active Directory / file services ---
        135 => ['RPC (Windows)', 'Directory'], 137 => ['NetBIOS', 'File sharing'], 138 => ['NetBIOS', 'File sharing'],
        139 => ['NetBIOS (SMB)', 'File sharing'], 445 => ['File sharing (SMB)', 'File sharing'],
        88 => ['Kerberos', 'Directory'], 464 => ['Kerberos', 'Directory'], 389 => ['LDAP (Directory)', 'Directory'],
        636 => ['LDAPS (Directory)', 'Directory'], 3268 => ['AD Global Catalog', 'Directory'], 3269 => ['AD Global Catalog', 'Directory'],
        9389 => ['AD Web Services', 'Directory'], 7680 => ['Windows Update (P2P)', 'Updates'],
        2049 => ['NFS', 'File sharing'], 111 => ['RPC portmapper', 'File sharing'], 873 => ['rsync', 'File sharing'],
        20 => ['FTP data', 'File sharing'], 21 => ['FTP', 'File sharing'], 989 => ['FTPS', 'File sharing'],
        990 => ['FTPS', 'File sharing'], 69 => ['TFTP', 'File sharing'], 115 => ['SFTP', 'File sharing'],
        515 => ['Printing (LPD)', 'Infrastructure'], 631 => ['Printing (IPP)', 'Infrastructure'], 9100 => ['Printing (RAW)', 'Infrastructure'],
        // --- Databases ---
        1433 => ['SQL Server', 'Database'], 1434 => ['SQL Server', 'Database'], 3306 => ['MySQL', 'Database'],
        5432 => ['PostgreSQL', 'Database'], 1521 => ['Oracle DB', 'Database'], 1830 => ['Oracle DB', 'Database'],
        50000 => ['DB2', 'Database'], 27017 => ['MongoDB', 'Database'], 27018 => ['MongoDB', 'Database'],
        6379 => ['Redis', 'Database'], 11211 => ['Memcached', 'Database'], 9042 => ['Cassandra', 'Database'],
        5984 => ['CouchDB', 'Database'], 8086 => ['InfluxDB', 'Database'], 9200 => ['Elasticsearch', 'Database'],
        // --- Voice / video / conferencing ---
        5060 => ['Voice (SIP)', 'Voice'], 5061 => ['Voice (SIP)', 'Voice'], 2000 => ['Voice (Cisco SCCP)', 'Voice'],
        1720 => ['Voice (H.323)', 'Voice'], 5004 => ['RTP media', 'Voice'], 5005 => ['RTP media', 'Voice'],
        3478 => ['STUN / TURN', 'Voice'], 3479 => ['STUN / TURN', 'Voice'], 19302 => ['WebRTC (STUN)', 'Voice'],
        554 => ['Video (RTSP)', 'Video surveillance'], 322 => ['Video (RTSPS)', 'Video surveillance'],
        37777 => ['Dahua NVR', 'Video surveillance'], 34567 => ['DVR', 'Video surveillance'],
        // --- VPN / tunnels ---
        500 => ['VPN (IKE)', 'VPN'], 4500 => ['VPN (IPsec NAT-T)', 'VPN'], 1194 => ['VPN (OpenVPN)', 'VPN'],
        1701 => ['VPN (L2TP)', 'VPN'], 1723 => ['VPN (PPTP)', 'VPN'], 51820 => ['VPN (WireGuard)', 'VPN'],
        10443 => ['SSL-VPN', 'VPN'],
        // --- Auth / AAA ---
        49 => ['TACACS+', 'Directory'], 1812 => ['RADIUS', 'Directory'], 1813 => ['RADIUS acct', 'Directory'],
        // --- Network / routing / mgmt ---
        161 => ['SNMP', 'Infrastructure'], 162 => ['SNMP trap', 'Infrastructure'], 514 => ['Syslog', 'Infrastructure'],
        6514 => ['Syslog (TLS)', 'Infrastructure'], 179 => ['BGP', 'Infrastructure'], 646 => ['MPLS LDP', 'Infrastructure'],
        1985 => ['HSRP', 'Infrastructure'], 3784 => ['BFD', 'Infrastructure'], 5246 => ['CAPWAP (AP mgmt)', 'Infrastructure'],
        5247 => ['CAPWAP (AP data)', 'Infrastructure'], 6343 => ['sFlow', 'Infrastructure'], 2055 => ['NetFlow', 'Infrastructure'],
        4739 => ['IPFIX', 'Infrastructure'], 830 => ['NETCONF', 'Management'], 6640 => ['OVSDB', 'Management'],
        // --- Messaging / collaboration ---
        5222 => ['XMPP', 'Chat'], 5223 => ['XMPP (TLS)', 'Chat'], 6667 => ['IRC', 'Chat'], 6697 => ['IRC (TLS)', 'Chat'],
        // --- Monitoring / observability / CI ---
        9090 => ['Prometheus', 'Monitoring'], 9093 => ['Alertmanager', 'Monitoring'], 3000 => ['Grafana', 'Monitoring'],
        10050 => ['Zabbix agent', 'Monitoring'], 10051 => ['Zabbix server', 'Monitoring'], 9997 => ['Splunk', 'Monitoring'],
        8089 => ['Splunk mgmt', 'Monitoring'], 4317 => ['OpenTelemetry', 'Monitoring'], 9418 => ['Git', 'Dev'],
        // --- Containers / orchestration ---
        6443 => ['Kubernetes API', 'Infrastructure'], 2375 => ['Docker', 'Infrastructure'], 2376 => ['Docker (TLS)', 'Infrastructure'],
        10250 => ['Kubelet', 'Infrastructure'], 2379 => ['etcd', 'Infrastructure'], 2380 => ['etcd', 'Infrastructure'],
        // --- Backup ---
        10000 => ['Backup / Webmin', 'Backup'], 13720 => ['NetBackup', 'Backup'], 13724 => ['NetBackup', 'Backup'],
        13782 => ['NetBackup', 'Backup'], 9392 => ['Veeam', 'Backup'], 6101 => ['Veritas', 'Backup'],
        // --- Directory-adjacent / misc infra ---
        88 => ['Kerberos', 'Directory'], 749 => ['Kerberos admin', 'Directory'], 3128 => ['Proxy (Squid)', 'Web'],
        1080 => ['SOCKS proxy', 'Web'], 8081 => ['Web / proxy', 'Web'], 8118 => ['Web proxy', 'Web'],
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
