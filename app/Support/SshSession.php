<?php

namespace App\Support;

use App\Models\Device;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

/**
 * Runs commands against a network device over SSH using a single authenticated
 * connection.
 *
 * Preferred path is an interactive shell (PTY): it can enter privileged (enable)
 * mode and disable paging, which some CLIs require. Silver Peak EdgeConnect, for
 * example, refuses the SSH exec channel entirely and only works over a shell.
 * Devices that instead grant only exec fall back to exec on the same session.
 */
class SshSession
{
    /** Per-vendor command to disable output paging (prevents a "--More--" hang). */
    private const PAGING_DISABLE = [
        'juniper' => 'set cli screen-length 0',
    ];

    /** Vendors whose CLI needs privileged (enable) mode for "show" commands. */
    private const ENABLE_VENDORS = ['silverpeak'];

    private const CONNECT_TIMEOUT = 10;
    private const READ_TIMEOUT = 12;

    /**
     * @param  array<int, string>  $commands
     * @return array<string, string> command => raw output
     */
    public static function run(Device $device, array $commands): array
    {
        // Each command costs up to READ_TIMEOUT, plus the shell/enable/paging
        // reads. Several commands (e.g. LLDP: conf t → int … → exit) can exceed
        // PHP's default max_execution_time, producing an UNCATCHABLE fatal 500
        // instead of a handled error. Give the request enough headroom so a slow
        // or unresponsive appliance surfaces as a normal timeout/exception.
        $budget = self::CONNECT_TIMEOUT + (count($commands) + 3) * self::READ_TIMEOUT + 15;
        @set_time_limit($budget);

        $ssh = new SSH2($device->ip_address, 22, self::CONNECT_TIMEOUT);

        if (! $ssh->login((string) $device->effectiveSshUsername(), (string) $device->effectiveSshCredential())) {
            throw new RuntimeException("SSH login failed for device {$device->id}");
        }

        $ssh->setTimeout(self::READ_TIMEOUT);

        try {
            return self::viaShell($ssh, $device, $commands);
        } catch (Throwable $e) {
            // Device refused a PTY/shell — reuse the same session for exec.
            return self::viaExec($ssh, $commands);
        }
    }

    /** Interactive shell: enable mode + paging off + write/read each command. */
    private static function viaShell(SSH2 $ssh, Device $device, array $commands): array
    {
        $ssh->enablePTY();
        $ssh->read(); // opens the shell + consumes the initial prompt (throws if PTY refused)

        if (in_array($device->vendor, self::ENABLE_VENDORS, true)) {
            $ssh->write("enable\n");
            $ssh->read();
        }

        if ($disable = self::PAGING_DISABLE[$device->vendor] ?? null) {
            $ssh->write($disable."\n");
            $ssh->read();
        }

        $output = [];
        foreach ($commands as $command) {
            $ssh->write($command."\n");
            $output[$command] = $ssh->read();
        }

        return $output;
    }

    /** Stateless per-command exec (no PTY / no persistent enable mode). */
    private static function viaExec(SSH2 $ssh, array $commands): array
    {
        $output = [];
        foreach ($commands as $command) {
            $result = $ssh->exec($command);
            $output[$command] = $result === false ? '' : (string) $result;
        }

        return $output;
    }
}
