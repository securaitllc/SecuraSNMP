<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\SyslogMessage;
use Illuminate\Console\Command;

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
                $refreshedAt = time();
            }

            $parsed = SyslogMessage::parse($data);
            SyslogMessage::create([
                'device_id' => $devicesByIp[$sourceIp] ?? null,
                'source_ip' => $sourceIp,
                'facility' => $parsed['facility'],
                'severity' => $parsed['severity'],
                'hostname' => $parsed['hostname'],
                'message' => $parsed['message'],
                'received_at' => now(),
            ]);
        }
    }
}
