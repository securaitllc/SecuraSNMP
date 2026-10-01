<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\SyslogMessage;
use App\Services\FortiGateLogAlarms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ListenSyslog extends Command
{
    protected $signature = 'syslog:listen {--port=514}';

    protected $description = 'Receives syslog messages over UDP and stores them, matching devices by source IP.';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $socket = @stream_socket_server("udp://0.0.0.0:{$port}", $errno, $errstr, STREAM_SERVER_BIND);

        if (! $socket) {
            $this->error("Could not bind udp/{$port}: {$errstr}");

            return self::FAILURE;
        }

        $this->info("Syslog listener started on udp/{$port}.");

        // Cache device IP -> id so each message isn't a DB lookup.
        $devicesByIp = Device::pluck('id', 'ip_address')->all();
        // Only firewalls are run through the log rules, so an ordinary switch line
        // can never match a FortiGate pattern by coincidence.
        $fortigates = Device::where('vendor', 'fortigate')->get()->keyBy('id');
        $refreshedAt = time();

        while (true) {
            $peer = '';
            $data = stream_socket_recvfrom($socket, 8192, 0, $peer);

            if ($data === false || $data === '') {
                continue;
            }

            $sourceIp = preg_replace('/:\d+$/', '', $peer);

            if (time() - $refreshedAt > 300) {
                $devicesByIp = Device::pluck('id', 'ip_address')->all();
                $fortigates = Device::where('vendor', 'fortigate')->get()->keyBy('id');
                $refreshedAt = time();
            }

            // A transient DB error or a malformed datagram must NOT crash the listener:
            // it's restart-on-death only, so an uncaught throw drops syslog for ~5s and
            // loses whatever the kernel buffered. Isolate each message instead.
            try {
                $parsed = SyslogMessage::parse($data);
                $deviceId = $devicesByIp[$sourceIp] ?? null;

                SyslogMessage::create([
                    'device_id' => $deviceId,
                    'source_ip' => $sourceIp,
                    'facility' => $parsed['facility'],
                    'severity' => $parsed['severity'],
                    'hostname' => $parsed['hostname'],
                    'message' => $parsed['message'],
                    'received_at' => now(),
                ]);

                // A FortiGate reports DNS and UTM failures only in its log — there is
                // no OID for "rating lookups are timing out", and the box keeps
                // forwarding traffic unrated while it happens. Storing the line is
                // not enough; somebody has to be told.
                if ($deviceId !== null) {
                    $device = $fortigates[$deviceId] ?? null;
                    if ($device !== null) {
                        FortiGateLogAlarms::evaluate($device, $parsed['message']);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Syslog message dropped: '.$e->getMessage());
            }
        }
    }
}
