<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CircuitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required', 'exists:sites,id'],
            'isp_provider_id' => ['nullable', 'exists:isp_providers,id'],
            'isp_name' => ['nullable', 'string', 'max:255'],
            'circuit_type' => ['required', 'in:fiber,cable,lte'],
            'ip_assignment' => ['nullable', 'in:static,dhcp'],
            // Monitoring method: direct ICMP, or a WAN-sourced ping from the SDWAN
            // (for DHCP circuits behind ISP NAT).
            'monitor_via' => ['nullable', 'in:icmp,sdwan'],
            // WAN- or LAN-side appliance port (e.g. wan0, lan1 for a cable modem
            // hung off the LAN side). EdgeConnect labels both; accept either.
            'wan_interface' => ['nullable', 'regex:/^(wan|lan)\d{1,2}$/'],
            'ping_target' => ['nullable', 'ip'],
            'circuit_id' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:255'],
            // The public IP to ping — the assigned static IP, or (on DHCP) whatever
            // the site currently holds.
            'monitored_ip' => ['required', 'ip'],
            'subnet' => ['nullable', 'string', 'max:255'],
            'gateway_ip' => ['nullable', 'ip'],
            // Last-mile carrier (LEC), distinct from the ISP/service contract.
            'lec_name' => ['nullable', 'string', 'max:255'],
            'lec_circuit_id' => ['nullable', 'string', 'max:255'],
            'lec_support_phone' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            // Contract accountability.
            'install_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'contract_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            // SLA uptime target (%). Null = fall back to the by-type default in reports.
            'sla_target_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Additional sites this circuit also serves (shared uplink).
            'shared_site_ids' => ['nullable', 'array'],
            'shared_site_ids.*' => ['integer', 'exists:sites,id'],
        ];
    }
}
