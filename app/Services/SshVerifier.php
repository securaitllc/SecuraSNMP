<?php

namespace App\Services;

use App\Models\Device;
use App\Models\NextHopAlert;
use App\Models\Tunnel;
use App\Support\SshError;
use App\Models\TunnelAlert;
use App\Models\TunnelMetricHistory;
use Illuminate\Support\Facades\Log;
use Throwable;

class SshVerifier
{
    private const COMMAND_TUNNELS = 'show tunnel';

    /**
     * @param callable(Device, array<int, string>): array<string, string> $executor
     *        Returns a command => raw output map for one SSH session.
     */
    public function __construct(private $executor)
    {
    }

    public static function forProduction(): self
    {
        // Network devices need an interactive shell (see SshSession), not the
        // SSH exec channel.
        return new self(fn (Device $device, array $commands): array => \App\Support\SshSession::run($device, $commands));
    }

    public function verifyAll(): void
    {
        Device::where('role', 'edgeconnect')
            // Reachable via a shared SSH credential, or via inline credentials.
            ->where(function ($query) {
                $query->whereNotNull('ssh_credential_id')
                    ->orWhere(fn ($q) => $q->whereNotNull('ssh_username')->whereNotNull('ssh_credential'));
            })
            ->with('sshCredential')
            ->get()
            ->each(function (Device $device) {
                try {
                    $this->verify($device);
                } catch (Throwable $e) {
                    Log::error("EdgeConnect verify failed for device {$device->id}: ".SshError::safe($e->getMessage()));
                }
            });
    }

    public function verify(Device $device): void
    {
        $commands = [self::COMMAND_TUNNELS];

        if ($device->next_hop_ip) {
            $commands[] = "ping {$device->next_hop_ip}";
        }

        $output = ($this->executor)($device, $commands);

        // EdgeConnect alarms are owned by EdgeConnectAlarmPoller (SNMP). SSH
        // verify is a connectivity + tunnel/next-hop check only, so it never
        // writes DeviceAlarm rows — avoiding duplicate alarms for one fault.
        $this->syncTunnels($device, $output[self::COMMAND_TUNNELS] ?? '');

        if ($device->next_hop_ip) {
            $this->checkNextHop($device, $output["ping {$device->next_hop_ip}"] ?? '');
        }
    }

    private function syncTunnels(Device $device, string $output): void
    {
        $parsed = $this->parseTunnels($output);
        $seen = [];

        foreach ($parsed as $tunnelName => $data) {
            $seen[] = $tunnelName;
            $tunnel = Tunnel::firstOrNew([
                'device_id' => $device->id,
                'tunnel_name' => $tunnelName,
            ]);

            // A tunnel FIRST SEEN down (e.g. a peer already removed from the
            // orchestrator when we started polling) is not a fresh fault — treat a
            // new tunnel as already in its current state, so only a real up->down
            // flap opens an alert. The alert auto-closes when the tunnel comes back
            // up (below).
            $wasUp = $tunnel->exists ? $tunnel->status === 'up' : ($data['status'] === 'up');
            $inDelta = $tunnel->exists ? max(0, $data['in_discards'] - $tunnel->in_discards) : 0;
            $outDelta = $tunnel->exists ? max(0, $data['out_discards'] - $tunnel->out_discards) : 0;

            $tunnel->fill([
                'peer' => $data['peer'] ?? null,
                'hub' => $data['hub'] ?? null,
                'status' => $data['status'],
                'in_discards' => $data['in_discards'],
                'out_discards' => $data['out_discards'],
                'in_discards_delta' => $inDelta,
                'out_discards_delta' => $outDelta,
                'last_checked_at' => now(),
            ])->save();

            TunnelMetricHistory::create([
                'tunnel_id' => $tunnel->id,
                'recorded_at' => now(),
                'status' => $data['status'],
                'in_discards_delta' => $inDelta,
                'out_discards_delta' => $outDelta,
            ]);

            if ($wasUp && $data['status'] === 'down') {
                TunnelAlert::create([
                    'tunnel_id' => $tunnel->id,
                    'started_at' => now(),
                ]);
            }

            if (! $wasUp && $data['status'] === 'up') {
                $tunnel->alerts()
                    ->whereNull('ended_at')
                    ->latest('started_at')
                    ->first()
                    ?->update(['ended_at' => now()]);
            }
        }

        // A tunnel that vanished from the appliance output no longer exists — the
        // peer was removed from the orchestrator (e.g. an SD-WAN appliance
        // decommissioned). It is gone, not down: delete it so it drops out of the
        // tunnel inventory, the per-hub panel, and the topology instead of lingering
        // as an un-clearable ghost. Cascade removes its alerts + metric history.
        // Only when the walk actually returned tunnels, so a transient SSH miss
        // doesn't wipe the whole inventory.
        if ($parsed !== []) {
            Tunnel::where('device_id', $device->id)
                ->whereNotIn('tunnel_name', $seen)
                ->delete();
        }
    }

    private function checkNextHop(Device $device, string $output): void
    {
        $reachable = $this->isNextHopReachable($output);

        if ($reachable === null) {
            return;
        }

        $openAlert = NextHopAlert::where('device_id', $device->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if (! $reachable && ! $openAlert) {
            NextHopAlert::create([
                'device_id' => $device->id,
                'started_at' => now(),
            ]);
        }

        if ($reachable && $openAlert) {
            $openAlert->update(['ended_at' => now()]);
        }
    }

    private function isNextHopReachable(string $output): ?bool
    {
        if (preg_match('/(\d+)%\s*packet loss/', $output, $matches)) {
            return (int) $matches[1] < 100;
        }

        return null;
    }

    /**
     * Parse Silver Peak `show tunnel` output — multi-line blocks, one per tunnel:
     *
     *   Tunnel to_HUB-A-PRI_DefaultOverlay(bondedTunnel_20) state
     *     Admin:               up
     *     Oper:                Up - Active
     *
     * We track only the BONDED overlay tunnels (the per-hub logical tunnels);
     * passthrough and the per-circuit underlay tunnels are noise. The name encodes
     * the peer (to_<peer>_<class>); the hub is the peer with its -PRI/-SEC suffix
     * dropped, so HUB-A-PRI and HUB-A-SEC roll up to one HUB-A hub.
     *
     * @return array<string, array{status: string, peer: ?string, hub: ?string, in_discards: int, out_discards: int}>
     */
    private function parseTunnels(string $output): array
    {
        $tunnels = [];
        $current = null;

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*Tunnel\s+(.+?)\((\w+)_\d+\)\s+state\s*$/', $line, $m)) {
                $current = null;
                if ($m[2] !== 'bondedTunnel') {
                    continue;
                }
                $name = trim($m[1]);
                $peer = null;
                $hub = null;
                if (preg_match('/^to_(.+)_[^_]+$/', $name, $pm)) {
                    $peer = $pm[1];
                    $hub = preg_replace('/-(PRI|SEC|PRIMARY|SECONDARY)$/i', '', $peer);
                }
                $current = $name;
                $tunnels[$name] = ['status' => 'up', 'peer' => $peer, 'hub' => $hub, 'in_discards' => 0, 'out_discards' => 0];

                continue;
            }

            if ($current !== null) {
                if (preg_match('/^\s*Oper:\s*(.+?)\s*$/', $line, $om)) {
                    $tunnels[$current]['status'] = preg_match('/^up/i', trim($om[1])) ? 'up' : 'down';
                } elseif (preg_match('/^\s*Admin:\s*down/i', $line)) {
                    $tunnels[$current]['status'] = 'down';
                } elseif (preg_match('/Rx Lost Pkts:\s*(\d+)/i', $line, $lp)) {
                    // The WAN "Rx Lost Pkts" is the tunnel's real loss counter.
                    $tunnels[$current]['in_discards'] = (int) $lp[1];
                }

                continue;
            }

            // Legacy single-line row "name up 0 0" (kept for back-compat + discard
            // tracking on appliances that emit the terse format).
            if (preg_match('/^(\S+)\s+(up|down)\s+(\d+)\s+(\d+)$/i', trim($line), $lm)) {
                $tunnels[$lm[1]] = ['status' => strtolower($lm[2]), 'peer' => null, 'hub' => null, 'in_discards' => (int) $lm[3], 'out_discards' => (int) $lm[4]];
            }
        }

        return $tunnels;
    }
}
