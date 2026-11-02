<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SshCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The password is never returned — only whether one is set.
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'has_password' => (bool) $this->password,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
