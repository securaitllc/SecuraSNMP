<?php

namespace App\Http\Requests;

use App\Support\WebhookUrl;
use Illuminate\Foundation\Http\FormRequest;

class NotificationChannelRequest extends FormRequest
{
    // Access control is enforced by the role:admin route middleware group.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // SSRF guard: webhook/Slack targets must be public/private-LAN http(s),
        // never loopback, link-local or cloud-metadata endpoints.
        $safeUrl = function (string $attribute, $value, $fail): void {
            if ($value && ! WebhookUrl::isSafe($value)) {
                $fail('The URL must be a valid http(s) endpoint that is not a loopback or link-local address.');
            }
        };

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:email,slack,webhook,teams'],
            'min_severity' => ['required', 'in:info,warning,critical'],
            'enabled' => ['boolean'],
            // Destination depends on the channel type.
            'config' => ['required', 'array'],
            'config.email' => ['required_if:type,email', 'nullable', 'email'],
            // Slack and Teams both use an incoming-webhook URL (same SSRF guard).
            'config.webhook_url' => ['required_if:type,slack,teams', 'nullable', 'url', $safeUrl],
            'config.url' => ['required_if:type,webhook', 'nullable', 'url', $safeUrl],
        ];
    }
}
