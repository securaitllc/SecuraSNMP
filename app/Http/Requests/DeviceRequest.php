<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'next_hop_ip' => ['nullable', 'ip'],
            'vendor' => ['required', 'in:juniper,silverpeak,fortigate'],
            'model' => ['required', 'string', 'max:255'],
            // Identity fields SNMP fills automatically, but editable by hand for
            // devices that don't advertise them (e.g. no serial/OS over SNMP).
            'serial_number' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'in:switch,edgeconnect,firewall'],
            // HA pairing: members share an ha_group; ha_role marks active/standby.
            'ha_group' => ['nullable', 'string', 'max:120'],
            'ha_role' => ['nullable', 'in:active,standby'],
            'snmp_version' => ['nullable', 'in:v2c,v3'],
            'snmp_community' => ['nullable', 'string'],
            'snmp_v3_username' => ['nullable', 'string'],
            'snmp_v3_auth_key' => ['nullable', 'string'],
            'snmp_v3_priv_key' => ['nullable', 'string'],
            'ssh_username' => ['nullable', 'string'],
            'ssh_credential' => ['nullable', 'string'],
            'ssh_credential_id' => ['nullable', 'exists:ssh_credentials,id'],
            'status' => ['required', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
