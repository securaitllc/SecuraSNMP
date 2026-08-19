<?php

namespace App\Services\Flow;

use App\Models\Device;
use App\Models\FlowRecord;
use Carbon\CarbonImmutable;

/**
 * Turns goflow2's decoded flow JSON into classified FlowRecord rows.
 *
 * goflow2 (the sidecar) receives sFlow (Juniper) + NetFlow/IPFIX (FortiGate), decodes
 * the binary + templates, and appends one JSON object per flow. This maps those fields,
 * scales sampled counts by the sampling rate, matches the exporter to a Device by its
 * agent/sampler IP, infers direction, and names the app via FlowClassifier. Pure of I/O
 * (takes an array of lines) so it's unit-testable; the file read/truncate lives in the
 * `flows:ingest` command.
 */
class FlowIngestor
{
    private const CHUNK = 500;

    public function __construct(private FlowClassifier $classifier)
    {
    }

    /**
     * @param  array<int, string|array<string,mixed>>  $lines  goflow2 JSON lines (or decoded arrays)
     * @return int  number of flow rows inserted
     */
    public function ingest(array $lines): int
    {
        // Map a flow's sampler/agent IP to a device by EITHER the monitored IP or the
        // configured flow-exporter IP — a switch often exports sFlow from me0 (off the
        // forwarding plane) while Nodus polls it on irb.x, so the two differ.
        $samplerToDevice = [];
        Device::select('id', 'ip_address', 'flow_exporter_ip')->get()->each(function (Device $d) use (&$samplerToDevice) {
            if ($d->ip_address) {
                $samplerToDevice[$d->ip_address] = $d->id;
            }
            if ($d->flow_exporter_ip) {
                $samplerToDevice[$d->flow_exporter_ip] = $d->id;
            }
        });
        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            $f = is_array($line) ? $line : json_decode((string) $line, true);
            if (! is_array($f)) {
                continue;
            }
            $src = $f['src_addr'] ?? null;
            $dst = $f['dst_addr'] ?? null;
            if (! $src || ! $dst) {
                continue;
            }

            $protocol = $this->classifier->protocol((int) ($f['proto'] ?? 0));
            $rate = max(1, (int) ($f['sampling_rate'] ?? 1));   // sampled → estimate the whole
            $dstPort = isset($f['dst_port']) ? (int) $f['dst_port'] : null;
            $srcPort = isset($f['src_port']) ? (int) $f['src_port'] : null;
            [$app, $category] = $this->classifier->classify((string) $dst, (string) $src, $dstPort, $srcPort, $protocol);
            $sampler = $f['sampler_address'] ?? null;

            $rows[] = [
                'device_id' => $sampler ? ($samplerToDevice[$sampler] ?? null) : null,
                'if_index' => isset($f['in_if']) ? (int) $f['in_if'] : null,
                'src_ip' => (string) $src,
                'dst_ip' => (string) $dst,
                'src_port' => $srcPort,
                'dst_port' => $dstPort,
                'protocol' => $protocol,
                'app' => $app,
                'app_category' => $category,
                'direction' => $this->direction((string) $src, (string) $dst),
                'bytes' => (int) ($f['bytes'] ?? 0) * $rate,
                'packets' => (int) ($f['packets'] ?? 0) * $rate,
                'flow_start' => $this->nsToTime($f['time_flow_start_ns'] ?? null),
                'recorded_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            FlowRecord::insert($chunk);
        }

        return count($rows);
    }

    /** inbound (internet→site), outbound (site→internet), or east-west (LAN↔LAN). */
    private function direction(string $src, string $dst): string
    {
        $sp = $this->isPrivate($src);
        $dp = $this->isPrivate($dst);

        return match (true) {
            $sp && $dp => 'east-west',
            $sp && ! $dp => 'outbound',
            ! $sp && $dp => 'inbound',
            default => 'transit',
        };
    }

    private function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false;
    }

    private function nsToTime(mixed $ns): ?CarbonImmutable
    {
        if (! $ns) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) ((int) $ns / 1_000_000_000));
    }
}
