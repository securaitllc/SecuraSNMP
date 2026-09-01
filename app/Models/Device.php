<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'ip_address',
        'next_hop_ip',
        'flow_exporter_ip',
        'vendor',
        'model',
        'os_version',
        'serial_number',
        'role',
        'ha_group',
        'ha_role',
        'lldp_enable_status',
        'lldp_enable_at',
        'snmp_version',
        'snmp_community',
        'snmp_v3_username',
        'snmp_v3_auth_key',
        'snmp_v3_priv_key',
        'ssh_username',
        'ssh_credential',
        'ssh_credential_id',
        'status',
        'notes',
        'vlan_source',
    ];

    protected $casts = [
        'snmp_community' => SafeEncrypted::class,
        'snmp_v3_auth_key' => SafeEncrypted::class,
        'snmp_v3_priv_key' => SafeEncrypted::class,
        'ssh_credential' => SafeEncrypted::class,
        'identity_attempted_at' => 'datetime',
        'identity_recheck_at' => 'datetime',
        'hardware_changed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sshCredential(): BelongsTo
    {
        return $this->belongsTo(SshCredential::class);
    }

    /** Prefer the shared SSH credential when linked, else the inline value. */
    public function effectiveSshUsername(): ?string
    {
        return $this->sshCredential?->username ?? $this->ssh_username;
    }

    public function effectiveSshCredential(): ?string
    {
        return $this->sshCredential?->password ?? $this->ssh_credential;
    }

    public function interfaces(): HasMany
    {
        return $this->hasMany(DeviceInterface::class);
    }

    public function alarms(): HasMany
    {
        return $this->hasMany(DeviceAlarm::class);
    }

    /**
     * Adds an `is_down` boolean (true when the device has an active `device-unreachable`
     * alarm). Reachability comes from THIS, not the admin `status` column — a monitored
     * device that stops answering ICMP is DOWN even though its status stays 'active'.
     */
    public function scopeWithReachability($query)
    {
        return $query->withExists(['alarms as is_down' => fn ($q) => $q
            ->where('alarm_id', 'device-unreachable')->whereNull('cleared_at')]);
    }

    /** True when an active device-unreachable alarm is loaded on this model. */
    /**
     * Is this device under planned maintenance right now?
     *
     * Maintenance is its own state — deliberately NOT a synonym for healthy. A device
     * being worked on is not reachable and must never read "up"; it reads "in
     * maintenance", so nobody concludes from a quiet dashboard that the box is fine.
     */
    public function inMaintenance(): bool
    {
        return MaintenanceWindow::suppresses($this->id, $this->site_id);
    }

    /** When the covering window ends, for the badge that says how long is left. */
    public function maintenanceUntil(): ?Carbon
    {
        return MaintenanceWindow::active()
            ->where(function ($q) {
                $q->where(fn ($g) => $g->whereNull('site_id')->whereNull('device_id'))
                    ->orWhere('device_id', $this->id)
                    ->orWhere('site_id', $this->site_id);
            })
            ->orderByDesc('ends_at')
            ->value('ends_at');
    }

    public function isDown(): bool
    {
        if ($this->relationLoaded('alarms')) {
            return $this->alarms->contains(fn ($a) => $a->alarm_id === 'device-unreachable' && $a->cleared_at === null);
        }

        return (bool) ($this->is_down ?? $this->alarms()->where('alarm_id', 'device-unreachable')->whereNull('cleared_at')->exists());
    }

    public function tunnels(): HasMany
    {
        return $this->hasMany(Tunnel::class);
    }

    public function nextHopAlerts(): HasMany
    {
        return $this->hasMany(NextHopAlert::class);
    }

    /** LLDP neighbors this device reported. */
    public function lldpNeighbors(): HasMany
    {
        return $this->hasMany(LldpNeighbor::class);
    }

    /** Next-hops the SD-WAN appliance reports (one per WAN uplink). */
    public function nextHops(): HasMany
    {
        return $this->hasMany(DeviceNextHop::class);
    }

    /**
     * Does this edge appliance still have a usable WAN path — at least one tunnel
     * up OR a next-hop gateway that isn't down? Used to grade a tunnel-down per
     * ITU-T X.733: if the SD-WAN still has a surviving path the outage is merely
     * DEGRADED (warning); only when every path is gone is the site isolated and
     * the alarm service-affecting (critical). Mirrors the topology DEGRADED signal.
     * Reads loaded relations when present to avoid N+1 inside the dashboard sweep.
     */
    public function hasWorkingWan(): bool
    {
        $upTunnels = $this->relationLoaded('tunnels')
            ? $this->tunnels->where('status', 'up')->count()
            : $this->tunnels()->where('status', 'up')->count();
        $upNextHops = $this->relationLoaded('nextHops')
            ? $this->nextHops->where('status', '!=', 'down')->count()
            : $this->nextHops()->where('status', '!=', 'down')->count();

        return $upTunnels > 0 || $upNextHops > 0;
    }

    public function metricHistory(): HasMany
    {
        return $this->hasMany(DeviceMetricHistory::class);
    }

    public function vlans(): HasMany
    {
        return $this->hasMany(DeviceVlan::class);
    }

    /** Virtual Chassis members (Juniper VC — one IP, several physical switches). */
    public function members(): HasMany
    {
        return $this->hasMany(DeviceMember::class)->orderBy('member_id');
    }

    public function health(): HasOne
    {
        return $this->hasOne(DeviceHealth::class);
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(DeviceSensor::class);
    }

    public function healthHistory(): HasMany
    {
        return $this->hasMany(DeviceHealthHistory::class);
    }

    public function snmpTraps(): HasMany
    {
        return $this->hasMany(SnmpTrap::class);
    }
}
