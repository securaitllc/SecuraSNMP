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

    /**
     * Live CPU/memory read for the device page's live health graph. A quick ICMP check
     * gates it: an unreachable device returns null instantly (no slow SNMP timeouts),
     * so the live graph shows the gap the moment the device goes dark. No DB write.
     */
    public function healthLive(Device $device): JsonResponse
    {
        $reachable = false;
        if ($device->ip_address) {
            $ping = new \Symfony\Component\Process\Process(['ping', '-c', '1', '-W', '1', $device->ip_address]);
            $ping->setTimeout(3);
            $ping->run();
            $reachable = $ping->isSuccessful();
        }

        if (! $reachable || (! $device->snmp_community && ! $device->snmp_v3_username)) {
            return response()->json(['cpu_pct' => null, 'mem_pct' => null, 'reachable' => $reachable, 'at' => now()->toIso8601String()]);
        }

        try {
            $r = (new \App\Services\HealthPoller(fn (Device $d, string $oid): string => $this->snmpWalk($d, $oid)))->probeCpuMem($device);
        } catch (\Throwable) {
            $r = ['cpu' => null, 'mem' => null];
        }

        return response()->json(['cpu_pct' => $r['cpu'], 'mem_pct' => $r['mem'], 'reachable' => true, 'at' => now()->toIso8601String()]);
    }

    /** Bounded snmpwalk (-t 2 -r 1, hard 6s kill) for the live health probe. */
    private function snmpWalk(Device $device, string $oid): string
    {
        if ($device->snmp_version === 'v3') {
            $cmd = ['snmpwalk', '-On', '-t', '2', '-r', '1', '-v3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key, $device->ip_address, $oid];
        } else {
            $cmd = ['snmpwalk', '-On', '-t', '2', '-r', '1', '-v2c', '-c', (string) $device->snmp_community, $device->ip_address, $oid];
        }

        $process = new \Symfony\Component\Process\Process($cmd);
        $process->setTimeout(6);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
