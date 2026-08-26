<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Live, on-demand reports. Availability is computed from INFRASTRUCTURE outage
 * spans (circuits, device reachability, SD-WAN tunnels) — never from access-port
 * interface flapping, which reflects users unplugging laptops for meetings, not a
 * real outage, and so can't be counted as an SLA breach.
 *
 * Each report declares its FULL set of columns; the caller picks which fields to
 * include. Every report returns the same shape — {title, columns, rows, summary}
 * — so the report page and the spreadsheet export are one generic codepath.
 */
class ReportService
{
    public const TYPES = [
        'circuit-availability' => 'Circuit / WAN Availability',
        'device-availability' => 'Device Availability',
        'tunnel-availability' => 'Tunnel / SD-WAN Availability',
        'alarm-summary' => 'Alarm & Incident Summary',
        'device-inventory' => 'Device Inventory',
        'circuit-contracts' => 'Circuit Contracts',
        'site-leases' => 'Site Leases',
    ];

    /** Column catalogue per report: key, label, optional align, and default-on. */
    private const COLUMNS = [
        'circuit-availability' => [
            ['key' => 'name', 'label' => 'Circuit', 'default' => true],
            ['key' => 'scope', 'label' => 'Site', 'default' => true],
            ['key' => 'uptime_pct', 'label' => 'Uptime %', 'align' => 'end', 'default' => true],
            ['key' => 'downtime_min', 'label' => 'Downtime (min)', 'align' => 'end', 'default' => true],
            ['key' => 'incidents', 'label' => 'Outages', 'align' => 'end', 'default' => true],
            ['key' => 'mttr_min', 'label' => 'MTTR (min)', 'align' => 'end', 'default' => true],
            ['key' => 'sla_target', 'label' => 'SLA target %', 'align' => 'end', 'default' => true],
            ['key' => 'sla_status', 'label' => 'SLA', 'default' => true],
            ['key' => 'downtime_budget_min', 'label' => 'Downtime budget (min)', 'align' => 'end', 'default' => false],
            ['key' => 'budget_used_pct', 'label' => 'Budget used %', 'align' => 'end', 'default' => false],
        ],
        'device-availability' => [
            ['key' => 'name', 'label' => 'Device', 'default' => true],
            ['key' => 'scope', 'label' => 'Site', 'default' => true],
            ['key' => 'uptime_pct', 'label' => 'Uptime %', 'align' => 'end', 'default' => true],
            ['key' => 'downtime_min', 'label' => 'Downtime (min)', 'align' => 'end', 'default' => true],
            ['key' => 'incidents', 'label' => 'Outages', 'align' => 'end', 'default' => true],
            ['key' => 'mttr_min', 'label' => 'MTTR (min)', 'align' => 'end', 'default' => true],
            ['key' => 'sla_target', 'label' => 'SLA target %', 'align' => 'end', 'default' => true],
            ['key' => 'sla_status', 'label' => 'SLA', 'default' => true],
            ['key' => 'downtime_budget_min', 'label' => 'Downtime budget (min)', 'align' => 'end', 'default' => false],
            ['key' => 'budget_used_pct', 'label' => 'Budget used %', 'align' => 'end', 'default' => false],
        ],
        'tunnel-availability' => [
            ['key' => 'name', 'label' => 'Tunnel', 'default' => true],
            ['key' => 'scope', 'label' => 'Device', 'default' => true],
            ['key' => 'uptime_pct', 'label' => 'Uptime %', 'align' => 'end', 'default' => true],
            ['key' => 'downtime_min', 'label' => 'Downtime (min)', 'align' => 'end', 'default' => true],
            ['key' => 'incidents', 'label' => 'Outages', 'align' => 'end', 'default' => true],
            ['key' => 'mttr_min', 'label' => 'MTTR (min)', 'align' => 'end', 'default' => true],
            ['key' => 'sla_target', 'label' => 'SLA target %', 'align' => 'end', 'default' => true],
            ['key' => 'sla_status', 'label' => 'SLA', 'default' => true],
            ['key' => 'downtime_budget_min', 'label' => 'Downtime budget (min)', 'align' => 'end', 'default' => false],
            ['key' => 'budget_used_pct', 'label' => 'Budget used %', 'align' => 'end', 'default' => false],
        ],
        'alarm-summary' => [
            ['key' => 'severity', 'label' => 'Severity', 'default' => true],
            ['key' => 'count', 'label' => 'Alarms', 'align' => 'end', 'default' => true],
            ['key' => 'open', 'label' => 'Still open', 'align' => 'end', 'default' => true],
            ['key' => 'total_min', 'label' => 'Total duration (min)', 'align' => 'end', 'default' => true],
            ['key' => 'mean_min', 'label' => 'Mean duration (min)', 'align' => 'end', 'default' => true],
        ],
        'device-inventory' => [
            ['key' => 'name', 'label' => 'Device Name', 'default' => true],
            ['key' => 'site_name', 'label' => 'Site', 'default' => true],
            ['key' => 'site_address', 'label' => 'Site Address', 'default' => false],
            ['key' => 'vendor', 'label' => 'Vendor', 'default' => true],
            ['key' => 'model', 'label' => 'Model', 'default' => true],
            ['key' => 'os_version', 'label' => 'OS Version', 'default' => true],
            ['key' => 'ip_address', 'label' => 'IP Address', 'default' => true],
            ['key' => 'serial_number', 'label' => 'Serial', 'default' => true],
            ['key' => 'role', 'label' => 'Role', 'default' => false],
            ['key' => 'status', 'label' => 'Admin Status', 'default' => true],
            ['key' => 'snmp_version', 'label' => 'SNMP', 'default' => false],
            ['key' => 'ha_group', 'label' => 'HA Group', 'default' => false],
            ['key' => 'next_hop_ip', 'label' => 'Next-hop', 'default' => false],
        ],
        'circuit-contracts' => [
            ['key' => 'name', 'label' => 'Circuit', 'default' => true],
            ['key' => 'site_name', 'label' => 'Site', 'default' => true],
            ['key' => 'isp_name', 'label' => 'ISP', 'default' => true],
            ['key' => 'install_date', 'label' => 'Installed', 'default' => true],
            ['key' => 'contract_end_date', 'label' => 'Contract Ends', 'default' => true],
            ['key' => 'days_to_expiry', 'label' => 'Days Left', 'align' => 'end', 'default' => true],
            ['key' => 'contract_status', 'label' => 'Status', 'default' => true],
            // The renew-or-move call: a contract signed past the site's lease end is
            // a liability if that location is given up.
            ['key' => 'lease_end_date', 'label' => 'Lease Ends', 'default' => true],
            ['key' => 'vs_lease', 'label' => 'Vs Lease', 'default' => true],
            ['key' => 'term_months', 'label' => 'Term (mo)', 'align' => 'end', 'default' => false],
            ['key' => 'renewals', 'label' => 'Renewals', 'align' => 'end', 'default' => false],
        ],
        'site-leases' => [
            ['key' => 'site_name', 'label' => 'Site', 'default' => true],
            ['key' => 'occupancy', 'label' => 'Occupancy', 'default' => true],
            ['key' => 'lease_end_date', 'label' => 'Lease Ends', 'default' => true],
            ['key' => 'days_left', 'label' => 'Days Left', 'align' => 'end', 'default' => true],
            ['key' => 'lease_status', 'label' => 'Status', 'default' => true],
            ['key' => 'circuits', 'label' => 'Circuits', 'align' => 'end', 'default' => true],
            ['key' => 'last_contract_end', 'label' => 'Last ISP Contract', 'default' => true],
            ['key' => 'decision', 'label' => 'Decision', 'default' => true],
            ['key' => 'region', 'label' => 'Region', 'default' => false],
            ['key' => 'lease_notes', 'label' => 'Lease Notes', 'default' => false],
        ],
    ];

    /** Default SLA target by circuit type when a circuit has no explicit override. */
    private const SLA_BY_TYPE = ['fiber' => 99.5, 'cable' => 99.5, 'lte' => 99.5];

    /** Internal fleet SLA target for devices/tunnels (no ISP contract to key off). */
    private const SLA_DEFAULT = 99.9;

    /** @return array{title:string,columns:array,rows:array,summary:array} */
    public function generate(string $type, CarbonInterface $start, CarbonInterface $end, array $filters = []): array
    {
        $built = match ($type) {
            'circuit-availability' => $this->circuitAvailability($start, $end, $filters),
            'device-availability' => $this->deviceAvailability($start, $end, $filters),
            'tunnel-availability' => $this->tunnelAvailability($start, $end, $filters),
            'alarm-summary' => $this->alarmSummary($start, $end, $filters),
            'device-inventory' => $this->deviceInventory($filters),
            'circuit-contracts' => $this->circuitContracts($filters),
            'site-leases' => $this->siteLeases($filters),
            default => throw new \InvalidArgumentException("Unknown report type: {$type}"),
        };

        return [
            'title' => self::TYPES[$type],
            'columns' => $this->selectColumns($type, $filters['fields'] ?? null),
            'rows' => $built['rows'],
            'summary' => $built['summary'],
        ];
    }

    /** The column catalogue for a report type (for the field picker). */
    public static function fieldsFor(string $type): array
    {
        return self::COLUMNS[$type] ?? [];
    }

    /**
     * Keep only the requested fields (or all defaults when none are specified),
     * in the ORDER the caller asked for — the field builder lets the user drag
     * columns into the order they want them exported.
     */
    private function selectColumns(string $type, ?array $fields): array
    {
        $all = self::COLUMNS[$type] ?? [];
        if ($fields === null || $fields === []) {
            return array_values(array_filter($all, fn ($c) => $c['default'] ?? false));
        }

        $byKey = [];
        foreach ($all as $c) {
            $byKey[$c['key']] = $c;
        }

        $out = [];
        foreach ($fields as $key) {
            if (isset($byKey[$key])) {
                $out[] = $byKey[$key];
            }
        }

        return $out;
    }

    private function circuitAvailability(CarbonInterface $start, CarbonInterface $end, array $filters): array
    {
        // Paused circuits (monitoring_enabled=false) are excluded — intentionally
        // not measured, so they'd falsely read 100%.
        $circuits = Circuit::where('monitoring_enabled', true)
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->with('site')->get();

        $spans = $this->spansByKey(CircuitAlert::whereIn('circuit_id', $circuits->pluck('id'))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))->get(), 'circuit_id');

        $periodMin = $this->periodMinutes($start, $end);
        $rows = $circuits->map(function (Circuit $c) use ($spans, $start, $end, $periodMin) {
            $avail = $this->fmtAvailability($spans[$c->id] ?? [], $start, $end);
            $target = $c->sla_target_pct ?? (self::SLA_BY_TYPE[$c->circuit_type] ?? self::SLA_DEFAULT);

            return ['name' => $c->circuit_id, 'scope' => $c->site?->name ?? '—']
                + $avail + $this->slaFields($avail['downtime_min'], (float) $target, $periodMin);
        })->all();

        return $this->availabilityEnvelope($rows);
    }

    private function deviceAvailability(CarbonInterface $start, CarbonInterface $end, array $filters): array
    {
        $devices = Device::where('status', 'active')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->with('site')->get();

        $spans = $this->spansByKey(DeviceAlarm::whereIn('device_id', $devices->pluck('id'))
            ->where('alarm_id', 'device-unreachable')
            ->where(fn ($q) => $q->whereNull('cleared_at')->orWhere('cleared_at', '>=', $start))->get()
            ->map(fn ($a) => (object) ['device_id' => $a->device_id, 'started_at' => $a->first_seen_at, 'ended_at' => $a->cleared_at]),
            'device_id');

        $periodMin = $this->periodMinutes($start, $end);
        $rows = $devices->map(function (Device $d) use ($spans, $start, $end, $periodMin) {
            $avail = $this->fmtAvailability($spans[$d->id] ?? [], $start, $end);

            return ['name' => $d->name, 'scope' => $d->site?->name ?? '—']
                + $avail + $this->slaFields($avail['downtime_min'], self::SLA_DEFAULT, $periodMin);
        })->all();

        return $this->availabilityEnvelope($rows);
    }

    private function tunnelAvailability(CarbonInterface $start, CarbonInterface $end, array $filters): array
    {
        $tunnels = Tunnel::with('device.site')
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->whereHas('device', fn ($d) => $d->where('site_id', $id)))->get();

        $spans = $this->spansByKey(TunnelAlert::whereIn('tunnel_id', $tunnels->pluck('id'))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))->get(), 'tunnel_id');

        $periodMin = $this->periodMinutes($start, $end);
        $rows = $tunnels->map(function (Tunnel $t) use ($spans, $start, $end, $periodMin) {
            $avail = $this->fmtAvailability($spans[$t->id] ?? [], $start, $end);

            return ['name' => $t->tunnel_name, 'scope' => $t->device?->name ?? '—']
                + $avail + $this->slaFields($avail['downtime_min'], self::SLA_DEFAULT, $periodMin);
        })->all();

        return $this->availabilityEnvelope($rows);
    }

    private function alarmSummary(CarbonInterface $start, CarbonInterface $end, array $filters): array
    {
        $alarms = DeviceAlarm::with('device.site')
            ->where('first_seen_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('cleared_at')->orWhere('cleared_at', '>=', $start))
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->whereHas('device', fn ($d) => $d->where('site_id', $id)))->get();

        $rows = $alarms->groupBy(fn ($a) => $a->severity ?? 'info')->map(function (Collection $group, string $sev) use ($start, $end) {
            $durations = $group->map(function ($a) use ($start, $end) {
                $s = $a->first_seen_at->greaterThan($start) ? $a->first_seen_at : $start;
                $e = ($a->cleared_at ?? $end);
                $e = $e->lessThan($end) ? $e : $end;

                return max(0, $e->getTimestamp() - $s->getTimestamp());
            });

            return [
                'severity' => ucfirst($sev),
                'count' => $group->count(),
                'open' => $group->whereNull('cleared_at')->count(),
                'total_min' => round($durations->sum() / 60, 1),
                'mean_min' => $group->count() ? round($durations->avg() / 60, 1) : 0,
            ];
        })->values()->all();

        $order = ['Critical' => 0, 'Warning' => 1, 'Info' => 2];
        usort($rows, fn ($a, $b) => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return [
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total alarms', 'value' => (string) $alarms->count()],
                ['label' => 'Still open', 'value' => (string) $alarms->whereNull('cleared_at')->count()],
                ['label' => 'Critical', 'value' => (string) $alarms->where('severity', 'critical')->count()],
            ],
        ];
    }

    private function deviceInventory(array $filters): array
    {
        $devices = Device::with('site', 'members')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->orderBy('name')->get();

        $rows = [];
        foreach ($devices as $d) {
            $base = [
                'name' => $d->name,
                'site_name' => $d->site?->name ?? '',
                'site_address' => $d->site?->address ?? '',
                'vendor' => $d->vendor ?? '',
                'model' => $d->model ?? '',
                'os_version' => $d->os_version ?? '',
                'ip_address' => $d->ip_address ?? '',
                'serial_number' => $d->serial_number ?? '',
                'role' => $d->role ?? '',
                'status' => $d->status ?? '',
                'snmp_version' => $d->snmp_version ?? '',
                'ha_group' => $d->ha_group ?? '',
                'next_hop_ip' => $d->next_hop_ip ?? '',
            ];

            // A Juniper Virtual Chassis answers on ONE management IP but is several
            // physical switches, each with its OWN serial (asset/warranty tracking
            // needs every one). The device row alone carried a single serial — so a
            // 6-member VC showed 1 serial instead of 6. Expand it: one row per member,
            // with that member's serial + model, keeping the shared site/IP/role.
            if ($d->members->count() > 1) {
                foreach ($d->members as $m) {
                    $rows[] = array_merge($base, [
                        'name' => $d->name.' · FPC'.$m->member_id,
                        'model' => $m->model ?: ($d->model ?? ''),
                        'serial_number' => $m->serial_number ?? '',
                        'role' => trim(($d->role ?? '').' · VC member '.$m->member_id),
                        'status' => $m->status ?: ($d->status ?? ''),
                    ]);
                }
            } else {
                $rows[] = $base;
            }
        }

        return [
            'rows' => $rows,
            'summary' => [
                // Physical units (VC members counted individually) — matches the serials.
                ['label' => 'Units', 'value' => (string) count($rows)],
                ['label' => 'Devices', 'value' => (string) $devices->count()],
                ['label' => 'Sites', 'value' => (string) $devices->pluck('site_id')->filter()->unique()->count()],
            ],
        ];
    }

    /** Contract-status snapshot: install/expiration dates, days left, renewals. */
    private function circuitContracts(array $filters): array
    {
        $circuits = Circuit::query()
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->with('site')->withCount('renewals')
            // Soonest expirations first; undated contracts last.
            ->orderByRaw('contract_end_date is null, contract_end_date asc')
            ->get();

        $label = ['expired' => 'Expired', 'warning' => 'Expiring ≤30d', 'notice' => 'Expiring ≤60d', 'ok' => 'OK', 'none' => 'No contract'];
        $rows = $circuits->map(fn (Circuit $c) => [
            'name' => $c->circuit_id,
            'site_name' => $c->site?->name ?? '—',
            'isp_name' => $c->isp_name ?? '—',
            'install_date' => $c->install_date?->toDateString() ?? '—',
            'contract_end_date' => $c->contract_end_date?->toDateString() ?? '—',
            'days_to_expiry' => $c->daysToExpiry() ?? '—',
            'contract_status' => $label[$c->contractStatus()],
            'lease_end_date' => $c->site?->isOwned() ? '—' : ($c->site?->lease_end_date?->toDateString() ?? '—'),
            'vs_lease' => self::contractVsLease($c),
            'term_months' => $c->contract_term_months ?? '—',
            'renewals' => $c->renewals_count,
        ])->all();

        return [
            'rows' => $rows,
            'summary' => [
                ['label' => 'Circuits', 'value' => (string) $circuits->count()],
                ['label' => 'Expired', 'value' => (string) $circuits->filter(fn ($c) => $c->contractStatus() === 'expired')->count()],
                ['label' => 'Expiring ≤60d', 'value' => (string) $circuits->filter(fn ($c) => in_array($c->contractStatus(), ['warning', 'notice'], true))->count()],
                ['label' => 'Past site lease', 'value' => (string) $circuits->filter(fn ($c) => self::outlivesLease($c))->count()],
            ],
        ];
    }

    /** Does this circuit's contract run past the end of its site's lease? */
    public static function outlivesLease(Circuit $c): bool
    {
        $site = $c->site;

        return $site && ! $site->isOwned() && $site->lease_end_date && $c->contract_end_date
            && $c->contract_end_date->gt($site->lease_end_date);
    }

    /**
     * One phrase for how a circuit's contract sits against its site's lease — the
     * whole point of recording the lease. Kept human ("Runs 14mo past lease") so a
     * report row is actionable without cross-referencing two dates by hand.
     */
    public static function contractVsLease(Circuit $c): string
    {
        $site = $c->site;
        if (! $site) {
            return '—';
        }
        if ($site->isOwned()) {
            return 'Owned site';
        }
        if (! $site->lease_end_date) {
            return 'No lease on file';
        }
        if (! $c->contract_end_date) {
            return 'No contract date';
        }
        if (! $c->contract_end_date->gt($site->lease_end_date)) {
            return 'Within lease';
        }
        $days = (int) $site->lease_end_date->startOfDay()->diffInDays($c->contract_end_date->startOfDay());

        return $days < 31
            ? "Runs {$days}d past lease"
            : 'Runs '.(int) round($days / 30).'mo past lease';
    }

    /**
     * Lease posture per location, soonest expiry first, owned/unrecorded last —
     * and next to each lease the furthest ISP contract at that site, so "can we
     * sign a 3-year circuit here?" is answered on one row.
     */
    private function siteLeases(array $filters): array
    {
        $sites = Site::query()
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->with('circuits')
            ->orderByRaw("occupancy = 'owned', lease_end_date is null, lease_end_date asc")
            ->orderBy('name')
            ->get();

        $label = ['expired' => 'Expired', 'warning' => 'Ends ≤90d', 'notice' => 'Ends ≤180d', 'ok' => 'OK', 'owned' => 'Owned', 'none' => 'Not set'];

        $rows = $sites->map(function (Site $s) use ($label) {
            $last = $s->circuits->filter(fn (Circuit $c) => $c->contract_end_date !== null)
                ->sortByDesc('contract_end_date')->first();
            $past = $last && ! $s->isOwned() && $s->lease_end_date && $last->contract_end_date->gt($s->lease_end_date);

            return [
                'site_name' => $s->name,
                'occupancy' => $s->isOwned() ? 'Owned' : 'Leased',
                'lease_end_date' => $s->lease_end_date && ! $s->isOwned() ? $s->lease_end_date->toDateString() : '—',
                'days_left' => $s->daysToLeaseEnd() ?? '—',
                'lease_status' => $label[$s->leaseStatus()],
                'circuits' => $s->circuits->count(),
                'last_contract_end' => $last?->contract_end_date?->toDateString() ?? '—',
                'decision' => self::leaseDecision($s, $past),
                'region' => $s->region ?? '—',
                'lease_notes' => $s->lease_notes ?? '—',
            ];
        })->all();

        return [
            'rows' => $rows,
            'summary' => [
                ['label' => 'Sites', 'value' => (string) $sites->count()],
                ['label' => 'Leased', 'value' => (string) $sites->reject(fn (Site $s) => $s->isOwned())->count()],
                ['label' => 'Owned', 'value' => (string) $sites->filter(fn (Site $s) => $s->isOwned())->count()],
                ['label' => 'Ends ≤180d', 'value' => (string) $sites->filter(fn (Site $s) => in_array($s->leaseStatus(), ['expired', 'warning', 'notice'], true))->count()],
            ],
        ];
    }

    /** The call this report exists to support, in plain words. */
    private static function leaseDecision(Site $site, bool $contractPastLease): string
    {
        if ($site->isOwned()) {
            return 'No lease constraint';
        }
        if (! $site->lease_end_date) {
            return 'Lease end not recorded yet';
        }
        if ($site->leaseStatus() === 'expired') {
            return 'Lease lapsed — confirm renewal or move';
        }
        if ($contractPastLease) {
            return 'Contract outlives lease — shorten term';
        }

        return 'Aligned — safe to renew ISP';
    }

    private function availabilityEnvelope(array $rows): array
    {
        usort($rows, fn ($a, $b) => $a['uptime_pct'] <=> $b['uptime_pct']); // worst first
        $count = count($rows);
        $avg = $count ? round(array_sum(array_column($rows, 'uptime_pct')) / $count, 3) : 100.0;

        return [
            'rows' => $rows,
            'summary' => [
                ['label' => 'Entities', 'value' => (string) $count],
                ['label' => 'Avg uptime', 'value' => number_format($avg, 3).'%'],
                ['label' => 'With outages', 'value' => (string) count(array_filter($rows, fn ($r) => $r['uptime_pct'] < 100))],
                ['label' => 'SLA breaches', 'value' => (string) count(array_filter($rows, fn ($r) => ($r['sla_status'] ?? '') === 'Breach'))],
            ],
        ];
    }

    private function fmtAvailability(array $spans, CarbonInterface $start, CarbonInterface $end): array
    {
        $a = self::availability($spans, $start, $end);

        return [
            'uptime_pct' => $a['uptime_pct'],
            'downtime_min' => round($a['downtime_seconds'] / 60, 1),
            'incidents' => $a['incidents'],
            'mttr_min' => $a['mttr_seconds'] !== null ? round($a['mttr_seconds'] / 60, 1) : null,
        ];
    }

    /**
     * SLA columns for an availability row: the target, the downtime budget the period
     * allows at that target, how much of it has been spent, and Met/Breach.
     *
     * @return array{sla_target: float, downtime_budget_min: float, budget_used_pct: int, sla_status: string}
     */
    private function slaFields(float $downtimeMin, float $targetPct, float $periodMinutes): array
    {
        $budget = round($periodMinutes * (1 - $targetPct / 100), 1);
        $used = $budget > 0 ? (int) round(($downtimeMin / $budget) * 100) : ($downtimeMin > 0 ? 999 : 0);

        return [
            'sla_target' => $targetPct,
            'downtime_budget_min' => $budget,
            'budget_used_pct' => $used,
            'sla_status' => $downtimeMin <= $budget ? 'Met' : 'Breach',
        ];
    }

    private function periodMinutes(CarbonInterface $start, CarbonInterface $end): float
    {
        return max(1, $end->getTimestamp() - $start->getTimestamp()) / 60;
    }

    /** @return array<int|string, array<int, array{started_at:Carbon, ended_at:?Carbon}>> */
    private function spansByKey(Collection $alerts, string $key): array
    {
        $out = [];
        foreach ($alerts as $alert) {
            $out[$alert->{$key}][] = ['started_at' => $alert->started_at, 'ended_at' => $alert->ended_at];
        }

        return $out;
    }

    /**
     * Uptime %, downtime, incidents and MTTR for a set of outage spans over a window.
     *
     * @param  array<int, array{started_at: Carbon, ended_at: ?Carbon}>  $spans
     * @return array{uptime_pct: float, downtime_seconds: int, incidents: int, mttr_seconds: ?int}
     */
    public static function availability(array $spans, CarbonInterface $start, CarbonInterface $end): array
    {
        $windowSeconds = max(1, $end->getTimestamp() - $start->getTimestamp());
        $downtime = 0;
        $incidents = 0;
        $resolvedDurations = [];

        foreach ($spans as $span) {
            $s = $span['started_at']->greaterThan($start) ? $span['started_at'] : $start;
            $e = ($span['ended_at'] ?? $end);
            $e = $e->lessThan($end) ? $e : $end;

            if ($e->greaterThan($s)) {
                $downtime += $e->getTimestamp() - $s->getTimestamp();
                $incidents++;
            }

            if ($span['ended_at']) {
                $resolvedDurations[] = $span['ended_at']->getTimestamp() - $span['started_at']->getTimestamp();
            }
        }

        $uptime = max(0, min(100, 100 - ($downtime / $windowSeconds * 100)));

        return [
            'uptime_pct' => round($uptime, 3),
            'downtime_seconds' => $downtime,
            'incidents' => $incidents,
            'mttr_seconds' => $resolvedDurations ? (int) round(array_sum($resolvedDurations) / count($resolvedDurations)) : null,
        ];
    }
}
