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
}
