<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SnmpCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('snmp_credential')?->id ?? $this->route('snmp_credential');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('snmp_credentials', 'name')->ignore($id)],
            'snmp_version' => ['required', 'in:v2c,v3'],
            'snmp_community' => ['nullable', 'required_if:snmp_version,v2c', 'string'],
            'snmp_v3_username' => ['nullable', 'required_if:snmp_version,v3', 'string'],
            'snmp_v3_auth_key' => ['nullable', 'required_if:snmp_version,v3', 'string'],
            'snmp_v3_priv_key' => ['nullable', 'required_if:snmp_version,v3', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
