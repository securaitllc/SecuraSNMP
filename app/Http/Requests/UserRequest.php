<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', Password::min(12)->mixedCase()->numbers()],
            'role' => ['required', 'in:super_admin,admin,analyst,viewer,display'],
            'is_active' => ['required', 'boolean'],
            'mfa_required' => ['sometimes', 'boolean'],
        ];
    }
}
