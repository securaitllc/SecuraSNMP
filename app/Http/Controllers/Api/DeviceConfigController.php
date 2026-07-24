<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceConfig;
use App\Services\ConfigBackupService;
use App\Support\LineDiff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceConfigController extends Controller
{
    /** Version list (metadata only) for a device, newest first. */
    public function index(Device $device): JsonResponse
    {
        return response()->json(
            $device->configs()
                ->latest('captured_at')
                ->get(['id', 'hash', 'line_count', 'captured_at'])
        );
    }

    public function show(DeviceConfig $deviceConfig): JsonResponse
    {
        return response()->json($deviceConfig->only(['id', 'device_id', 'content', 'hash', 'line_count', 'captured_at']));
    }

    /** Diff two versions (defaults to the latest two). */
    public function diff(Request $request, Device $device): JsonResponse
    {
        $versions = $device->configs()->latest('captured_at')->take(2)->get();

        $from = $request->query('from')
            ? $device->configs()->find($request->query('from'))
            : ($versions[1] ?? null);
        $to = $request->query('to')
            ? $device->configs()->find($request->query('to'))
            : ($versions[0] ?? null);

        return response()->json([
            'from' => $from?->only(['id', 'captured_at']),
            'to' => $to?->only(['id', 'captured_at']),
            'diff' => LineDiff::diff($from?->content ?? '', $to?->content ?? ''),
        ]);
    }

    /** Trigger an on-demand backup now. */
    public function store(Device $device): JsonResponse
    {
        if (! $device->effectiveSshUsername() || ! $device->effectiveSshCredential()) {
            return response()->json(['error' => 'No SSH credential resolved for this device. Link an SSH credential (or set inline SSH username/password).'], 422);
        }

        try {
            $config = ConfigBackupService::forProduction()->backup($device);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Backup failed: '.\App\Support\SshError::safe($e->getMessage())], 502);
        }

        return response()->json([
            'changed' => $config !== null,
            'config_id' => $config?->id,
        ], $config ? 201 : 200);
    }
}
