<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'min_severity' => $this->min_severity,
            'enabled' => $this->enabled,
            // Webhook/Slack URLs are secret and masked; an email address is not.
            'destination' => $this->destinationHint(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function destinationHint(): ?string
    {
        return match ($this->type) {
            'email' => $this->config['email'] ?? null,
            'slack', 'webhook' => '••••••',
            default => null,
        };
    }
}
