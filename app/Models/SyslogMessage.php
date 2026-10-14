<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyslogMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'source_ip',
        'facility',
        'severity',
        'hostname',
        'message',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public const SEVERITY_LABELS = [
        0 => 'emergency', 1 => 'alert', 2 => 'critical', 3 => 'error',
        4 => 'warning', 5 => 'notice', 6 => 'info', 7 => 'debug',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Parse a raw syslog datagram (RFC 3164/5424 header) into fields. The
     * leading <PRI> encodes facility (PRI>>3) and severity (PRI&7); the rest is
     * kept as the message (host best-effort).
     *
     * @return array{facility: ?int, severity: ?int, hostname: ?string, message: string}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        $facility = null;
        $severity = null;
        $hostname = null;
        $message = $raw;

        if (preg_match('/^<(\d{1,3})>(.*)$/s', $raw, $m)) {
            $pri = (int) $m[1];
            $facility = intdiv($pri, 8);
            $severity = $pri % 8;
            $message = trim($m[2]);
        }

        // Best-effort host: "<PRI>Mmm dd HH:MM:SS host rest" (RFC3164).
        if (preg_match('/^[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+(\S+)\s+(.*)$/s', $message, $h)) {
            $hostname = $h[1];
            $message = $h[2];
        }

        return ['facility' => $facility, 'severity' => $severity, 'hostname' => $hostname, 'message' => $message];
    }
}
