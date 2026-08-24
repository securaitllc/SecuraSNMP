<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SnmpCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Secrets are never returned — only whether they are set.
        return [
            'id' => $this->id,
            'name' => $this->name,
            'snmp_version' => $this->snmp_version,
            'has_community' => (bool) $this->snmp_community,
            'snmp_v3_username' => $this->snmp_v3_username,
            'has_v3_auth_key' => (bool) $this->snmp_v3_auth_key,
            'has_v3_priv_key' => (bool) $this->snmp_v3_priv_key,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
