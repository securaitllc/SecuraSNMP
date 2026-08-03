<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'site_type' => ['nullable', 'in:hub,branch'],
            // A branch may home to a hub; must reference an actual hub site, and
            // never itself. (Legacy single-hub — kept for compatibility.)
            'hub_site_id' => ['nullable', 'exists:sites,id', 'different:id'],
            // A branch can home to multiple hubs (Massey has 2).
            'hub_site_ids' => ['nullable', 'array'],
            'hub_site_ids.*' => ['integer', 'exists:sites,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
