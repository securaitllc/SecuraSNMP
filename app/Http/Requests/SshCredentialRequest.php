<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SshCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('ssh_credential')?->id ?? $this->route('ssh_credential');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('ssh_credentials', 'name')->ignore($id)],
            'username' => ['required', 'string', 'max:255'],
            // Required on create; on update, keep the stored secret when omitted.
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
