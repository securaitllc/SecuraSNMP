<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'next_hop_ip' => $this->next_hop_ip,
            'flow_exporter_ip' => $this->flow_exporter_ip,
            'vendor' => $this->vendor,
            'model' => $this->model,
            'os_version' => $this->os_version,
            'serial_number' => $this->serial_number,
            'role' => $this->role,
            // Maintenance is its own state — never folded into "up". A device being
            // worked on is genuinely unreachable, and saying otherwise would hide it.
            'in_maintenance' => $this->inMaintenance(),
            'maintenance_until' => $this->maintenanceUntil(),
            'ha_group' => $this->ha_group,
            'ha_role' => $this->ha_role,
            'lldp_enable_status' => $this->lldp_enable_status,
            'lldp_enable_at' => $this->lldp_enable_at,
            'snmp_version' => $this->snmp_version,
            'snmp_community' => $this->snmp_community ? '••••••' : null,
            // Usernames are an auth component (an SNMPv3/SSH username for reachable
            // gear is recon a low-priv operator shouldn't get), so gate them to admins
            // — same trust level as the already-masked secret values. Non-admins simply
            // don't get these keys.
            'snmp_v3_username' => $this->when($request->user()?->role === 'admin', $this->snmp_v3_username),
            'snmp_v3_auth_key' => $this->snmp_v3_auth_key ? '••••••' : null,
            'snmp_v3_priv_key' => $this->snmp_v3_priv_key ? '••••••' : null,
            'ssh_username' => $this->when($request->user()?->role === 'admin', $this->ssh_username),
            'ssh_credential' => $this->ssh_credential ? '••••••' : null,
            'ssh_credential_id' => $this->ssh_credential_id,
            'ssh_credential_name' => $this->whenLoaded('sshCredential', fn () => $this->sshCredential?->name),
            'health' => $this->whenLoaded('health'),
            'sensors' => $this->whenLoaded('sensors'),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'member_id' => $m->member_id,
                'serial_number' => $m->serial_number,
                'model' => $m->model,
                'role' => $m->role,
                'sw_version' => $m->sw_version,
                'priority' => $m->priority,
                'status' => $m->status,
                'absent_since' => $m->absent_since,
            ])),
            'status' => $this->status,
            // Reachability (ICMP), distinct from the admin `status` column — a device
            // with an active device-unreachable alarm is DOWN even while status='active'.
            'is_down' => $this->isDown(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
