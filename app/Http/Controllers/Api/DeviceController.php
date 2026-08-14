<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = Device::query()
            ->withReachability()
            ->with('sshCredential:id,name')
            ->when($request->query('site_id'), fn ($query, $siteId) => $query->where('site_id', $siteId))
            ->when($request->query('vendor'), fn ($query, $vendor) => $query->where('vendor', $vendor))
            ->when($request->query('role'), fn ($query, $role) => $query->where('role', $role))
            ->orderBy('name')
            ->get();

        return DeviceResource::collection($devices)->response();
    }

    public function store(DeviceRequest $request): JsonResponse
    {
        $device = Device::create($request->validated());

        return (new DeviceResource($device))->response()->setStatusCode(201);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load('sshCredential:id,name', 'health', 'sensors', 'members');

        return (new DeviceResource($device))->response();
    }

    public function update(DeviceRequest $request, Device $device): JsonResponse
    {
        $device->update($request->validated());

        return (new DeviceResource($device))->response();
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return response()->json(null, 204);
    }

    /**
     * One ICMP probe RIGHT NOW — drives the live ICMP graph on the device page. Kept
     * deliberately lightweight (single `ping -c 1`, hard 4s cap) and separate from the
     * heavier looking-glass ping tool, so the page can poll it every couple of seconds.
     */
    public function ping(Device $device): JsonResponse
    {
        if (! $device->ip_address) {
            return response()->json(['rtt_ms' => null, 'reachable' => false, 'at' => now()->toIso8601String()]);
        }

        $process = new \Symfony\Component\Process\Process(['ping', '-c', '1', '-W', '2', $device->ip_address]);
        $process->setTimeout(4);
        $process->run();

        $rtt = null;
        if ($process->isSuccessful() && preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $process->getOutput(), $m)) {
            $rtt = round((float) $m[1], 1);
        }

        return response()->json(['rtt_ms' => $rtt, 'reachable' => $rtt !== null, 'at' => now()->toIso8601String()]);
    }
}
