<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationChannelRequest;
use App\Http\Resources\NotificationChannelResource;
use App\Models\NotificationChannel;
use App\Services\AlertNotifier;
use Illuminate\Http\JsonResponse;

class NotificationChannelController extends Controller
{
    public function index(): JsonResponse
    {
        return NotificationChannelResource::collection(
            NotificationChannel::orderBy('name')->get()
        )->response();
    }

    public function store(NotificationChannelRequest $request): JsonResponse
    {
        $channel = NotificationChannel::create($request->validated());

        return (new NotificationChannelResource($channel))->response()->setStatusCode(201);
    }

    public function update(NotificationChannelRequest $request, NotificationChannel $notificationChannel): JsonResponse
    {
        $notificationChannel->update($request->validated());

        return (new NotificationChannelResource($notificationChannel))->response();
    }

    public function destroy(NotificationChannel $notificationChannel): JsonResponse
    {
        $notificationChannel->delete();

        return response()->json(null, 204);
    }

    /** Send a test notification and report whether it was delivered. */
    public function test(NotificationChannel $notificationChannel): JsonResponse
    {
        $log = AlertNotifier::test($notificationChannel);

        return response()->json(['status' => $log->status, 'error' => $log->error]);
    }
}
