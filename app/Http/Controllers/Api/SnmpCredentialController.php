<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SnmpCredentialRequest;
use App\Http\Resources\SnmpCredentialResource;
use App\Models\SnmpCredential;
use Illuminate\Http\JsonResponse;

class SnmpCredentialController extends Controller
{
    public function index(): JsonResponse
    {
        return SnmpCredentialResource::collection(
            SnmpCredential::orderBy('name')->get()
        )->response();
    }

    public function store(SnmpCredentialRequest $request): JsonResponse
    {
        $credential = SnmpCredential::create($request->validated());

        return (new SnmpCredentialResource($credential))->response()->setStatusCode(201);
    }

    public function update(SnmpCredentialRequest $request, SnmpCredential $snmpCredential): JsonResponse
    {
        $snmpCredential->update($request->validated());

        return (new SnmpCredentialResource($snmpCredential))->response();
    }

    public function destroy(SnmpCredential $snmpCredential): JsonResponse
    {
        $snmpCredential->delete();

        return response()->json(null, 204);
    }
}
