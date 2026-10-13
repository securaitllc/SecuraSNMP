<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\SshCredential;
use App\Services\SshCredentialAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moving devices between shared SSH credential profiles, in bulk.
 *
 * Replacing an appliance replaces its login. Doing that one device at a time through
 * the editor is slow and, worse, silent when it goes wrong: nothing in the interface
 * fails, the SSH pollers just stop being able to log in, and the tunnel and next-hop
 * tables go stale underneath pages that still render them.
 *
 * So the credential is proven against the device before it is stored. See
 * SshCredentialAssigner for the reasoning.
 */
class DeviceCredentialController extends Controller
{
    /**
     * The devices whose hardware was actually swapped, with the profile each one is
     * on now. This is the candidate list for a reassignment — derived from the serial
     * trail rather than typed out, because "the ones we replaced" is a fact the
     * platform already holds and a human list is a human error.
     */
    public function replaced(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:3650']]);
        $devices = SshCredentialAssigner::recentlyReplaced($data['days'] ?? 30);

        return response()->json([
            'days' => $data['days'] ?? 30,
            'data' => $devices->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'ip_address' => $d->ip_address,
                'role' => $d->role,
                'vendor' => $d->vendor,
                'site_name' => $d->site?->name,
                'ssh_credential_id' => $d->ssh_credential_id,
                'ssh_credential_name' => $d->sshCredential?->name,
                'ssh_username' => $d->sshCredential?->username,
            ])->values(),
        ]);
    }

    /**
     * Point the named devices at one shared credential profile.
     *
     * With verification on (the default) each device is authenticated first and only
     * the ones that answer are written. Devices that refuse keep the credential they
     * had and are listed in the response with a reason.
     */
    public function bulkAssign(Request $request, SshCredentialAssigner $assigner): JsonResponse
    {
        $verify = $request->boolean('verify', true);

        // A verified batch is bounded by the gateway timeout, not by appetite: every
        // unreachable device costs the full connect timeout and nginx gives up at 120
        // seconds. See SshCredentialAssigner::VERIFIED_BATCH_MAX.
        $cap = $verify ? SshCredentialAssigner::VERIFIED_BATCH_MAX : SshCredentialAssigner::MAX_DEVICES;

        $data = $request->validate([
            'device_ids' => ['required', 'array', 'min:1', 'max:'.$cap],
            'device_ids.*' => ['integer', 'distinct', 'exists:devices,id'],
            'ssh_credential_id' => ['required', 'integer', 'exists:ssh_credentials,id'],
            // Waiving verification is for devices that are genuinely unreachable and
            // whose new credential the operator already knows. It is never the default.
            'verify' => ['nullable', 'boolean'],
        ], [
            'device_ids.max' => $verify
                ? 'Verify at most '.SshCredentialAssigner::VERIFIED_BATCH_MAX
                    .' devices per request — each one is logged into in turn, and a longer run outlasts the gateway timeout. Send them in batches.'
                : 'Assign at most '.SshCredentialAssigner::MAX_DEVICES.' devices at once.',
        ]);

        $target = SshCredential::findOrFail($data['ssh_credential_id']);
        $devices = Device::with(['site:id,name', 'sshCredential:id,name'])
            ->whereIn('id', $data['device_ids'])
            ->get();

        $summary = $assigner->assign($devices, $target, $verify, $request->user());

        return response()->json([
            'credential' => ['id' => $target->id, 'name' => $target->name, 'username' => $target->username],
            'verified' => $verify,
            ...$summary,
        ]);
    }
}
