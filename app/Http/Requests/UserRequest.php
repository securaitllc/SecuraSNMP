<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    /**
     * You may not act on an account that outranks you.
     *
     * The route is gated at role:admin, which was read as "admins manage users" —
     * but super_admin is a rank above admin, so without this an admin could edit,
     * demote or reassign the one account that gates the OSINT tool.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        if (! $target instanceof User) {
            return true;    // create: nothing to outrank yet; the role rule does the work
        }

        return Roles::rank($target->role) <= Roles::rank($this->user()?->role);
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', Password::min(12)->mixedCase()->numbers()],
            // Your own rank and below — never above. An admin mints admins, not
            // super-admins, and cannot promote themselves by editing their own row.
            'role' => ['required', Rule::in(Roles::assignableBy($this->user()?->role))],
            'is_active' => ['required', 'boolean'],
            'mfa_required' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.in' => 'You cannot assign a role above your own.',
        ];
    }
}
