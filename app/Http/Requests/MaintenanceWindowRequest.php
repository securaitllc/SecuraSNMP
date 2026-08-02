<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'device_id' => ['nullable', 'exists:devices,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
