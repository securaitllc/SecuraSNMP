<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\SshVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeviceVerifyController extends Controller
{
    public function __construct(private SshVerifier $verifier)
    {
    }

    public function store(Device $device): JsonResponse
    {
        if ($device->role !== 'edgeconnect') {
            return response()->json(['error' => 'Only EdgeConnect devices can be verified over SSH.'], 422);
        }

        if (! $device->effectiveSshUsername() || ! $device->effectiveSshCredential()) {
            return response()->json(['error' => 'No SSH credential resolved for this device. Link an SSH credential (or set inline SSH username/password) on the device.'], 422);
        }

        try {
            $this->verifier->verify($device);
        } catch (Throwable $e) {
            Log::error("On-demand verify failed for device {$device->id}: {$e->getMessage()}");

            // Categorised, secret-free reason so the operator can act.
            return response()->json(['error' => 'SSH verification failed: '.\App\Support\SshError::safe($e->getMessage())], 502);
        }

        return response()->json([
            'alarms' => $device->alarms()->whereNull('cleared_at')->orderByDesc('first_seen_at')->get(),
            'tunnels' => $device->tunnels()->orderBy('tunnel_name')->get(),
            'next_hop_reachable' => ! $device->nextHopAlerts()->whereNull('ended_at')->exists(),
        ]);
    }
}
