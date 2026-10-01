<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceHardwareChange;
use App\Models\SshCredential;
use App\Models\User;
use App\Support\SshError;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Moves a set of devices onto a shared SSH credential profile, one authentication
 * at a time, and writes the change only where the new credential actually works.
 *
 * Hardware replacement is why this exists. When an appliance is swapped, the box on
 * the end of the wire is a different box: same site, same address, same name, new
 * serial — and often a different login. Until now the only way to re-point thirty
 * replaced devices at the right profile was thirty trips through the device editor,
 * and a mistake anywhere in that sequence is invisible. Nothing in the UI fails; the
 * SSH pollers simply stop being able to log in, and tunnel and next-hop data goes
 * stale while every page still renders it.
 *
 * That is the failure this guards against. A credential is proven against the device
 * BEFORE it is saved: connect, authenticate, disconnect. A device that refuses the
 * new login keeps the one it had and is reported, because a device left on a working
 * credential is recoverable and a fleet left on a broken one is an outage nobody
 * gets told about.
 *
 * Verification can be waived (`$verify = false`) for the case it cannot serve: a
 * device that is genuinely unreachable right now, where the operator knows the new
 * credential is correct and would rather the record be right than wait for the box
 * to come back.
 */
class SshCredentialAssigner
{
    /**
     * Long enough for a loaded appliance to answer, short enough that a batch
     * containing a few dead devices still finishes. Unreachable devices dominate
     * the running time of any batch — each one costs the full timeout.
     */
    public const CONNECT_TIMEOUT = 8;

    /**
     * A ceiling on one batch when nothing is verified. Writes are fast; this is only
     * here so one request cannot be made arbitrarily large.
     */
    public const MAX_DEVICES = 100;

    /**
     * A ceiling on one VERIFIED batch, derived from the gateway rather than guessed.
     *
     * docker/nginx.conf sets `fastcgi_read_timeout 120`, and an unreachable device
     * costs the full CONNECT_TIMEOUT. Twelve devices is 96 seconds of worst case,
     * inside that 120 with room for the rest of the request. Raising this without
     * raising the nginx timeout buys a 504 — and a 504 mid-batch leaves the operator
     * with no report, even though each device commits atomically and nothing is
     * corrupted. The front end chunks a larger selection into runs of this size.
     */
    public const VERIFIED_BATCH_MAX = 12;

    /**
     * What the trail records as the origin of the change.
     *
     * These MUST fit device_hardware_changes.source, which is varchar(16). SQLite
     * ignores column widths and MySQL does not, so an over-long value here passes
     * every local test and then throws SQLSTATE 22001 on production — which is
     * exactly what shipped in v0.10.63: 'bulk-assign-verified' is 20 characters.
     * DeviceHardwareChange::SOURCE_MAX and the test that checks it exist so the
     * next person cannot repeat it.
     */
    public const SOURCE_VERIFIED = 'bulk-verified';

    public const SOURCE_UNVERIFIED = 'bulk-assign';

    /** @var callable(Device, string, string): true */
    private $prober;

    /**
     * @param  callable(Device, string, string): true|null  $prober  throws on failure;
     *                                                               injected so the
     *                                                               decision logic is
     *                                                               testable without a
     *                                                               network.
     */
    public function __construct(?callable $prober = null)
    {
        $this->prober = $prober ?? self::sshProbe();
    }

    /**
     * Authenticate and immediately disconnect. No command is run: the question is
     * only whether this credential opens this device.
     *
     * @return callable(Device, string, string): true
     */
    public static function sshProbe(): callable
    {
        return function (Device $device, string $username, string $password): true {
            $ssh = new SSH2($device->ip_address, 22, self::CONNECT_TIMEOUT);
            $ssh->setTimeout(self::CONNECT_TIMEOUT);

            try {
                if (! $ssh->login($username, $password)) {
                    throw new \RuntimeException('authentication rejected');
                }
            } finally {
                $ssh->disconnect();
            }

            return true;
        };
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{applied: int, failed: int, unchanged: int, results: array<int, array<string, mixed>>}
     */
    public function assign(Collection $devices, SshCredential $target, bool $verify = true, ?User $user = null): array
    {
        // Each verified device costs up to CONNECT_TIMEOUT. Without headroom a batch
        // of unreachable devices hits PHP's limit and dies as an uncatchable fatal
        // mid-way, having written some rows and not others.
        if ($verify) {
            @set_time_limit(30 + $devices->count() * (self::CONNECT_TIMEOUT + 2));
        }

        $username = (string) $target->username;
        $password = (string) $target->password;
        $results = [];

        foreach ($devices as $device) {
            $results[] = $this->one($device, $target, $username, $password, $verify, $user);
        }

        $tally = fn (string $status) => count(array_filter($results, fn ($r) => $r['status'] === $status));

        $summary = [
            'applied' => $tally('applied'),
            'failed' => $tally('failed'),
            'unchanged' => $tally('unchanged'),
            'results' => $results,
        ];

        Log::info('Bulk SSH credential assignment', [
            'credential' => $target->name,
            'verified' => $verify,
            'applied' => $summary['applied'],
            'failed' => $summary['failed'],
            'unchanged' => $summary['unchanged'],
            'by' => $user?->name,
        ]);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function one(Device $device, SshCredential $target, string $username, string $password, bool $verify, ?User $user): array
    {
        $row = fn (string $status, ?string $reason = null) => array_filter([
            'device_id' => $device->id,
            'device_name' => $device->name,
            'site_name' => $device->site?->name,
            'status' => $status,
            'reason' => $reason,
            'from' => $device->sshCredential?->name,
            'to' => $target->name,
        ], fn ($v) => $v !== null);

        // Already there. Not a failure and not work — saying so keeps the applied
        // count meaning "devices this run actually moved".
        if ((int) $device->ssh_credential_id === (int) $target->id) {
            return $row('unchanged', 'already on this profile');
        }

        if ($verify) {
            if (blank($device->ip_address)) {
                return $row('failed', 'no management address to verify against');
            }

            try {
                ($this->prober)($device, $username, $password);
            } catch (Throwable $e) {
                // SshError::safe strips anything that could echo a secret or a host
                // detail back into an API response.
                return $row('failed', SshError::safe($e->getMessage()));
            }
        }

        $previous = $device->sshCredential?->name;

        // One transaction, because the device row and its trail row are one fact.
        // v0.10.63 wrote them separately: the trail insert threw on production, the
        // device row was already committed, and the fleet was left with a device
        // whose credential had changed and no record that it had. A half-applied
        // batch is the one outcome this whole service exists to avoid.
        try {
            DB::transaction(function () use ($device, $target, $previous, $verify, $user) {
                $device->forceFill([
                    'ssh_credential_id' => $target->id,
                    // An inline password left behind on the device would silently win
                    // back if the profile link were ever cleared. Replacing the profile
                    // means replacing the credential, so the stale copy goes.
                    'ssh_credential' => null,
                    'ssh_username' => null,
                ])->save();

                // The same trail that records a swapped serial. A credential
                // reassignment belongs in a device's history for the same reason: it
                // changes how the device is reached, and the next person debugging a
                // login failure needs to see that it moved and when. Profile NAMES
                // only — no secret is written to the trail.
                DeviceHardwareChange::record(
                    $device,
                    'ssh_credential',
                    $previous ?: 'inline credential',
                    $target->name,
                    $verify ? self::SOURCE_VERIFIED : self::SOURCE_UNVERIFIED,
                    $user,
                );
            });
        } catch (Throwable $e) {
            // Report it against the device rather than failing the whole batch: the
            // other twenty-five devices in the run are not implicated by this one's
            // write failing, and an aborted request is how one bad row became a
            // half-finished migration.
            Log::error('Credential reassignment failed to commit', [
                'device_id' => $device->id,
                'credential' => $target->name,
                'error' => $e->getMessage(),
            ]);

            return $row('failed', 'the change could not be saved — nothing was altered for this device');
        }

        return $row('applied');
    }

    /**
     * Devices whose serial changed inside the window — the hardware that was
     * physically replaced, as opposed to devices somebody renamed or upgraded.
     *
     * @return Collection<int, Device>
     */
    public static function recentlyReplaced(int $days): Collection
    {
        $ids = DeviceHardwareChange::query()
            ->where('field', 'serial_number')
            ->where('detected_at', '>=', now()->subDays($days))
            ->distinct()
            ->pluck('device_id');

        return Device::with(['site:id,name', 'sshCredential:id,name,username'])
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }
}
