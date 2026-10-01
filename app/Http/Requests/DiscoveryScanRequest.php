<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscoveryScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'snmp_credential_id' => ['required', 'exists:snmp_credentials,id'],
            'subnets' => ['required', 'array', 'min:1'],
            'subnets.*' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! self::isCidr($value)) {
                    $fail("The {$attribute} field must be a valid IPv4 CIDR (e.g. 10.15.0.0/22).");
                }
            }],
        ];
    }

    private static function isCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return false;
        }

        [$network, $prefix] = explode('/', $value, 2);

        return filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($prefix)
            && (int) $prefix >= 0
            && (int) $prefix <= 32;
    }
}
