<?php

namespace App\Models;

use App\Casts\SafeEncrypted;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'ip_address',
        'next_hop_ip',
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
    ];

    protected $casts = [
        'snmp_community' => SafeEncrypted::class,
        'snmp_v3_auth_key' => SafeEncrypted::class,
        'snmp_v3_priv_key' => SafeEncrypted::class,
        'ssh_credential' => SafeEncrypted::class,
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
