<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IspProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('isp_provider')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('isp_providers', 'name')->ignore($id)],
            'support_phone' => ['nullable', 'string', 'max:255'],
            'ticket_url' => ['nullable', 'url', 'max:2048'],
            'account_rep_name' => ['nullable', 'string', 'max:255'],
            'account_rep_mobile' => ['nullable', 'string', 'max:255'],
            'account_rep_phone' => ['nullable', 'string', 'max:255'],
            'account_rep_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
