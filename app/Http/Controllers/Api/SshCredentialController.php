<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SshCredentialRequest;
use App\Http\Resources\SshCredentialResource;
use App\Models\SshCredential;
use Illuminate\Http\JsonResponse;

class SshCredentialController extends Controller
{
    public function index(): JsonResponse
    {
        return SshCredentialResource::collection(
            SshCredential::orderBy('name')->get()
        )->response();
    }

    public function store(SshCredentialRequest $request): JsonResponse
    {
        $credential = SshCredential::create($request->validated());

        return (new SshCredentialResource($credential))->response()->setStatusCode(201);
    }

    public function update(SshCredentialRequest $request, SshCredential $sshCredential): JsonResponse
    {
        $data = $request->validated();

        // Blank password on update means "leave the stored secret unchanged".
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $sshCredential->update($data);

        return (new SshCredentialResource($sshCredential))->response();
    }

    public function destroy(SshCredential $sshCredential): JsonResponse
    {
        $sshCredential->delete();

        return response()->json(null, 204);
    }
}
