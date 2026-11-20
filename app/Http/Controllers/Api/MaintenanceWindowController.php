<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceWindowRequest;
use App\Models\MaintenanceWindow;
use Illuminate\Http\JsonResponse;

class MaintenanceWindowController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            MaintenanceWindow::with(['site:id,name', 'device:id,name'])
                ->orderByDesc('starts_at')
                ->get()
        );
    }

    public function store(MaintenanceWindowRequest $request): JsonResponse
    {
        $window = MaintenanceWindow::create($request->validated());

        return response()->json($window->load(['site:id,name', 'device:id,name']), 201);
    }

    public function update(MaintenanceWindowRequest $request, MaintenanceWindow $maintenanceWindow): JsonResponse
    {
        $maintenanceWindow->update($request->validated());

        return response()->json($maintenanceWindow->load(['site:id,name', 'device:id,name']));
    }

    public function destroy(MaintenanceWindow $maintenanceWindow): JsonResponse
    {
        $maintenanceWindow->delete();

        return response()->json(null, 204);
    }
}
