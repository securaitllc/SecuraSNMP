<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OsintCaseController;
use App\Http\Controllers\Api\OsintController;
use App\Http\Controllers\Api\OsintIntegrationController;
use App\Http\Controllers\Api\CircuitAlertController;
use App\Http\Controllers\Api\CircuitController;
use App\Http\Controllers\Api\CircuitMetricController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceAlarmController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceInterfaceController;
use App\Http\Controllers\Api\DeviceLldpController;
use App\Http\Controllers\Api\PollerHealthController;
use App\Http\Controllers\Api\VulnerabilityController;
use App\Http\Controllers\Api\DeviceNeighborController;
use App\Http\Controllers\Api\DeviceMetricController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\DeviceVlanController;
use App\Http\Controllers\Api\DeviceHealthController;
use App\Http\Controllers\Api\DeviceToolController;
use App\Http\Controllers\Api\DeviceVerifyController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\DiscoveryScanController;
use App\Http\Controllers\Api\InterfaceAlertController;
use App\Http\Controllers\Api\InterfaceMetricController;
use App\Http\Controllers\Api\MaintenanceWindowController;
use App\Http\Controllers\Api\MailSettingController;
use App\Http\Controllers\Api\NotificationChannelController;
use App\Http\Controllers\Api\NotificationLogController;
use App\Http\Controllers\Api\IspProviderController;
use App\Http\Controllers\Api\NextHopAlertController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\CircuitImportController;
use App\Http\Controllers\Api\DeviceImportController;
use App\Http\Controllers\Api\SiteImportController;
use App\Http\Controllers\Api\TopologyController;
use App\Http\Controllers\Api\SnmpCredentialController;
use App\Http\Controllers\Api\SshCredentialController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SnmpTrapController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SyslogController;
use App\Http\Controllers\Api\TunnelAlertController;
use App\Http\Controllers\Api\TunnelController;
use App\Http\Controllers\Api\TunnelMetricController;
use App\Http\Controllers\Api\UpdateController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Rate-limit login to blunt credential brute-forcing — per-account AND per-IP
// (see the 'login' limiter in AppServiceProvider).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/password', [AuthController::class, 'changePassword'])->middleware('throttle:8,1');
    Route::post('/avatar', [AuthController::class, 'updateAvatar']);

    // Two-factor enrolment (reachable while un-enrolled so forced setup can finish).
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/enroll', [TwoFactorController::class, 'enroll'])->middleware('throttle:12,1');
    Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:12,1');
    Route::post('/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:6,1');

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/insights', [DashboardController::class, 'insights']);
    Route::get('/anomalies', [\App\Http\Controllers\Api\AnomalyController::class, 'index']);

    Route::get('/version', [UpdateController::class, 'version']);

    // Reports — live, selectable reports (availability, alarm summary, inventory)
    // with field selection + spreadsheet export.
    Route::get('/reports/catalog', [ReportController::class, 'catalog']);
    Route::get('/reports/{type}/export', [ReportController::class, 'export']);
    Route::get('/reports/{type}', [ReportController::class, 'generate']);

    Route::get('/sites', [SiteController::class, 'index']);
    // Registered before the /sites/{site} wildcard is fine (distinct suffix), but
    // kept adjacent for clarity: per-site NOC overview (devices + circuits + health).
    Route::get('/sites/{site}/overview', [SiteController::class, 'overview']);
    Route::get('/sites/{site}/topology', [TopologyController::class, 'site']);
    // Operator-arranged topology layout (global per site) — a NOC action, analyst+.
    Route::post('/sites/{site}/topology/positions', [TopologyController::class, 'savePositions'])->middleware('role:analyst');
    Route::delete('/sites/{site}/topology/positions', [TopologyController::class, 'resetPositions'])->middleware('role:analyst');
    Route::get('/sites/{site}', [SiteController::class, 'show']);

    // Poller liveness — heartbeat freshness for every long-running loop, so a
    // stalled poller (a recovered circuit that never re-pings and never clears)
    // is visible in seconds instead of silently dead for hours.
    Route::get('/health/pollers', [PollerHealthController::class, 'index']);

    // Passive vulnerability posture — device firmware correlated against the CVE
    // catalog (no device scanning). Read = viewer; acknowledge = analyst.
    Route::get('/vulnerabilities/summary', [VulnerabilityController::class, 'summary']);
    Route::get('/vulnerabilities', [VulnerabilityController::class, 'index']);
    Route::get('/devices/{device}/vulnerabilities', [VulnerabilityController::class, 'forDevice']);
    Route::post('/vulnerabilities/{vulnerability}/acknowledge', [VulnerabilityController::class, 'acknowledge'])->middleware('role:analyst');

    // Organization-wide topology roll-up (all sites, chain status).
    Route::get('/topology', [TopologyController::class, 'organization']);

    Route::get('/devices', [DeviceController::class, 'index']);
    // Registered before the /devices/{device} wildcard so "metrics" is not
    // captured as a device id.
    Route::get('/devices/metrics/summary', [DeviceMetricController::class, 'summary']);
    Route::get('/devices/metrics', [DeviceMetricController::class, 'index']);
    Route::get('/devices/{device}', [DeviceController::class, 'show']);
    // NetFlow/sFlow — the Flows tab: KPI summary, top talkers, per-app DPI, KQL search.
    Route::get('/devices/{device}/flows/summary', [FlowController::class, 'summary']);
    Route::get('/devices/{device}/flows/top-talkers', [FlowController::class, 'topTalkers']);
    Route::get('/devices/{device}/flows/apps', [FlowController::class, 'apps']);
    Route::get('/flows/exporters', [FlowController::class, 'exporters']);
    Route::get('/flows/overview', [FlowController::class, 'overview']);
    Route::get('/flows/timeseries', [FlowController::class, 'timeseries']);
    Route::get('/flows/values', [FlowController::class, 'values']);
    Route::get('/flows/resolve', [FlowController::class, 'resolve']);
    Route::get('/flows/search', [FlowController::class, 'search']);
    Route::get('/devices/{device}/next-hop-alerts', [NextHopAlertController::class, 'index']);
    Route::get('/devices/{device}/vlans', [DeviceVlanController::class, 'index']);
    // What LLDP says is plugged into each port — surfaced on the device panel,
    // not only inside the topology payload.
    Route::get('/devices/{device}/neighbors', [DeviceNeighborController::class, 'index']);
    // Pull the latest LLDP neighbors for one device on demand (spawns snmpwalk).
    Route::post('/devices/{device}/lldp/refresh', [DeviceLldpController::class, 'refresh'])->middleware(['role:analyst', 'throttle:10,1']);
    Route::get('/devices/{device}/traps', [SnmpTrapController::class, 'index']);
    // Interface alert history for a device — cleared ones included, so a clear
    // leaves a record instead of the alert simply disappearing.
    Route::get('/devices/{device}/interface-alerts', [InterfaceAlertController::class, 'forDevice']);
    Route::get('/devices/{device}/health-history', [DeviceHealthController::class, 'history']);
    Route::post('/devices/{device}/verify', [DeviceVerifyController::class, 'store'])->middleware(['throttle:20,1', 'role:analyst']);
    // One live ICMP probe for the device page's live graph (read-only, poll-friendly).
    Route::post('/devices/{device}/ping', [DeviceController::class, 'ping'])->middleware('throttle:60,1');
    // Live CPU/memory read for the live health graph (ICMP-gated, no DB write).
    Route::post('/devices/{device}/health-live', [DeviceController::class, 'healthLive'])->middleware('throttle:40,1');

    Route::get('/interfaces', [DeviceInterfaceController::class, 'index']);
    // Literal routes before any /interfaces/{wildcard}.
    Route::get('/interfaces/top', [DeviceInterfaceController::class, 'top']);
    Route::get('/interfaces/sparklines', [DeviceInterfaceController::class, 'sparklines']);
    Route::get('/interfaces/{interface}/alerts', [InterfaceAlertController::class, 'index']);
    // NOC actions on an interface-down alert (analyst or admin — not read-only viewers).
    Route::post('/interface-alerts/{alert}/acknowledge', [InterfaceAlertController::class, 'acknowledge'])->middleware('role:analyst');
    Route::post('/interface-alerts/{alert}/clear', [InterfaceAlertController::class, 'clear'])->middleware('role:analyst');
    // Proactive health actions on an interface (errors/discards/flapping pill).
    Route::post('/interfaces/{interface}/note', [DeviceInterfaceController::class, 'saveNote'])->middleware('role:analyst');
    Route::post('/interfaces/{interface}/ack-health', [DeviceInterfaceController::class, 'acknowledgeHealth'])->middleware('role:analyst');
    Route::get('/interfaces/metrics', [InterfaceMetricController::class, 'index']);

    Route::get('/alarms', [DeviceAlarmController::class, 'index']);
    // Dependency-aware root-cause incidents (upstream failure + suppressed downstream cascade).
    Route::get('/incidents', [\App\Http\Controllers\Api\IncidentController::class, 'index']);
    // Searchable Active + history alarm log (dedicated Alarms page).
    Route::get('/alarms/log', [DeviceAlarmController::class, 'log']);
    // Active alarms grouped by site → ISP circuit (per-provider ticket/dispatch view).
    Route::get('/alarms/grouped', [DeviceAlarmController::class, 'grouped']);
    // Learned-MAC lookup (search by MAC / vendor, or scoped to a device/interface).
    Route::get('/mac-addresses', [\App\Http\Controllers\Api\MacAddressController::class, 'index']);
    Route::post('/alarms/{alarm}/acknowledge', [DeviceAlarmController::class, 'acknowledge'])->middleware('role:analyst');
    Route::post('/alarms/{alarm}/clear', [DeviceAlarmController::class, 'clear'])->middleware('role:analyst');
    // Bulk clear — registered before the {alarm} wildcard so "bulk-clear" is not
    // captured as an alarm id.
    Route::post('/alarms/bulk-clear', [DeviceAlarmController::class, 'bulkClear'])->middleware('role:analyst');

    // Syslog viewer (read; searchable).
    Route::get('/syslog', [SyslogController::class, 'index']);

    Route::get('/search', [SearchController::class, 'index']);

    Route::get('/tunnels', [TunnelController::class, 'index']);
    Route::get('/tunnels/{tunnel}/alerts', [TunnelAlertController::class, 'index']);
    Route::post('/tunnel-alerts/{alert}/acknowledge', [TunnelAlertController::class, 'acknowledge'])->middleware('role:analyst');
    Route::post('/tunnel-alerts/{alert}/clear', [TunnelAlertController::class, 'clear'])->middleware('role:analyst');
    Route::get('/tunnels/metrics', [TunnelMetricController::class, 'index']);

    Route::get('/isp-providers', [IspProviderController::class, 'index']);
    Route::get('/isp-providers/{isp_provider}/overview', [IspProviderController::class, 'overview']);

    Route::get('/circuits', [CircuitController::class, 'index']);
    // Registered before the /circuits/{circuit} wildcard so "metrics" is not
    // captured as a circuit id.
    Route::get('/circuits/metrics/summary', [CircuitMetricController::class, 'summary']);
    Route::get('/circuits/metrics', [CircuitMetricController::class, 'index']);
    Route::get('/circuits/{circuit}', [CircuitController::class, 'show']);
    Route::get('/circuits/{circuit}/alerts', [CircuitAlertController::class, 'index']);
    // Merged outage story: circuit + EdgeConnect + next-hop alerts, envelope, flapping.
    Route::get('/circuits/{circuit}/history', [CircuitController::class, 'history']);
    // Contract renewal history (accountability trail) — readable by any role.
    Route::get('/circuits/{circuit}/renewals', [CircuitController::class, 'renewals']);
    // NOC actions on a circuit outage (analyst or admin, mirroring the alarm
    // ack/clear workflow): record the ISP ticket, acknowledge, clear, dispatch.
    Route::post('/circuits/{circuit}/ticket', [CircuitAlertController::class, 'ticket'])->middleware('role:analyst');
    // Current ISP ticket + field-dispatch ETA on the circuit (work even when there's no
    // open outage — e.g. an SD-WAN transport degrade). Single source of truth for both pages.
    Route::post('/circuits/{circuit}/isp-ticket', [CircuitController::class, 'setIspTicket'])->middleware('role:analyst');
    Route::post('/circuits/{circuit}/isp-dispatch', [CircuitController::class, 'setDispatch'])->middleware('role:analyst');
    Route::post('/circuits/{circuit}/acknowledge', [CircuitAlertController::class, 'acknowledge'])->middleware('role:analyst');
    Route::post('/circuits/{circuit}/clear', [CircuitAlertController::class, 'clear'])->middleware('role:analyst');
    Route::post('/circuits/{circuit}/dispatch', [CircuitAlertController::class, 'dispatch'])->middleware('role:analyst');
    // Take a circuit in/out of monitoring (planned disconnect / maintenance) —
    // a config change, so admin-only (the ack/clear NOC actions above stay open).
    Route::post('/circuits/{circuit}/monitoring', [CircuitController::class, 'setMonitoring'])->middleware('role:admin');

    Route::middleware('role:admin')->group(function () {
        Route::post('/sites/geocode', [SiteController::class, 'geocode']);
        Route::post('/sites/import', [SiteImportController::class, 'import']);
        Route::post('/devices/import', [DeviceImportController::class, 'import']);
        Route::post('/circuits/import', [CircuitImportController::class, 'import']);
        Route::post('/circuits/dedupe', [CircuitController::class, 'dedupe']);
        Route::post('/sites', [SiteController::class, 'store']);
        Route::put('/sites/{site}', [SiteController::class, 'update']);
        Route::delete('/sites/{site}', [SiteController::class, 'destroy']);

        Route::post('/devices', [DeviceController::class, 'store']);
        Route::put('/devices/{device}', [DeviceController::class, 'update']);
        Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);
        Route::post('/devices/{device}/lldp/enable', [DeviceLldpController::class, 'enable']);

        // Mute false "interface down" alarms (unused admin-up ports) at onboarding.
        Route::post('/interfaces/suppress-down', [DeviceInterfaceController::class, 'suppressDown']);
        Route::post('/interfaces/{interface}/suppress', [DeviceInterfaceController::class, 'suppress']);
        Route::post('/interfaces/{interface}/unsuppress', [DeviceInterfaceController::class, 'unsuppress']);

        Route::post('/isp-providers', [IspProviderController::class, 'store']);
        Route::put('/isp-providers/{isp_provider}', [IspProviderController::class, 'update']);
        Route::delete('/isp-providers/{isp_provider}', [IspProviderController::class, 'destroy']);

        Route::post('/circuits', [CircuitController::class, 'store']);
        Route::post('/circuits/{circuit}/renew', [CircuitController::class, 'renew']);
        Route::put('/circuits/{circuit}', [CircuitController::class, 'update']);
        Route::delete('/circuits/{circuit}', [CircuitController::class, 'destroy']);
        Route::put('/circuits/{circuit}/alerts/{alert}', [CircuitAlertController::class, 'update']);

        // Reusable SNMP credential profiles (secrets encrypted at rest, never
        // returned by the API). Discovery is an admin/NOC-engineer function.
        Route::get('/snmp-credentials', [SnmpCredentialController::class, 'index']);
        Route::post('/snmp-credentials', [SnmpCredentialController::class, 'store']);
        Route::put('/snmp-credentials/{snmp_credential}', [SnmpCredentialController::class, 'update']);
        Route::delete('/snmp-credentials/{snmp_credential}', [SnmpCredentialController::class, 'destroy']);

        // Shared SSH credential profiles (password encrypted at rest, never
        // returned). Devices link to one instead of storing inline creds.
        Route::get('/ssh-credentials', [SshCredentialController::class, 'index']);
        Route::post('/ssh-credentials', [SshCredentialController::class, 'store']);
        Route::put('/ssh-credentials/{ssh_credential}', [SshCredentialController::class, 'update']);
        Route::delete('/ssh-credentials/{ssh_credential}', [SshCredentialController::class, 'destroy']);

        // Alerting & notifications.
        Route::get('/notification-channels', [NotificationChannelController::class, 'index']);
        Route::post('/notification-channels', [NotificationChannelController::class, 'store']);
        Route::put('/notification-channels/{notification_channel}', [NotificationChannelController::class, 'update']);
        Route::delete('/notification-channels/{notification_channel}', [NotificationChannelController::class, 'destroy']);
        Route::post('/notification-channels/{notification_channel}/test', [NotificationChannelController::class, 'test'])->middleware('throttle:15,1');

        Route::get('/mail-settings', [MailSettingController::class, 'show']);
        Route::put('/mail-settings', [MailSettingController::class, 'update']);
        Route::post('/mail-settings/test', [MailSettingController::class, 'test'])->middleware('throttle:6,1');

        Route::get('/maintenance-windows', [MaintenanceWindowController::class, 'index']);
        Route::post('/maintenance-windows', [MaintenanceWindowController::class, 'store']);
        Route::put('/maintenance-windows/{maintenance_window}', [MaintenanceWindowController::class, 'update']);
        Route::delete('/maintenance-windows/{maintenance_window}', [MaintenanceWindowController::class, 'destroy']);

        Route::get('/notification-logs', [NotificationLogController::class, 'index']);

        Route::get('/discovery/scans', [DiscoveryScanController::class, 'index']);
        Route::post('/discovery/scans', [DiscoveryScanController::class, 'store']);
        Route::get('/discovery/scans/{discovery_scan}', [DiscoveryScanController::class, 'show']);
        Route::delete('/discovery/scans/{discovery_scan}', [DiscoveryScanController::class, 'destroy']);
        Route::post('/discovery/scans/{discovery_scan}/import', [DiscoveryScanController::class, 'import']);
        Route::post('/discovery/discovered/{discovered_device}/ignore', [DiscoveryScanController::class, 'ignore']);

        // Looking glass — on-demand ping/traceroute/snmpwalk (rate-limited: each
        // call spawns a process).
        Route::post('/devices/{device}/tools/{tool}', [DeviceToolController::class, 'run'])->middleware('throttle:15,1');

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        // Admin unenroll — reset a user's 2FA so they register a fresh authenticator.
        Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::get('/updates/check', [UpdateController::class, 'check']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    // OSINT tool — SUPER-ADMIN only (a tier above admin; existing admins are excluded).
    // Lookups shell out to local tools + external APIs and are audit-logged. Domain/phone
    // lookups spawn a process or hit a provider, so they're rate-limited.
    Route::middleware('role:super_admin')->prefix('osint')->group(function () {
        Route::get('/integrations', [OsintIntegrationController::class, 'index']);
        Route::post('/integrations/{provider}', [OsintIntegrationController::class, 'update']);
        Route::post('/integrations/{provider}/test', [OsintIntegrationController::class, 'test']);

        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/lookup/domain', [OsintController::class, 'domain']);
            Route::post('/lookup/ip', [OsintController::class, 'ip']);
            Route::post('/lookup/phone', [OsintController::class, 'phone']);
        });

        Route::get('/cases', [OsintCaseController::class, 'index']);
        Route::post('/cases', [OsintCaseController::class, 'store']);
        Route::get('/cases/{case}', [OsintCaseController::class, 'show']);
        Route::post('/cases/{case}/iocs', [OsintCaseController::class, 'addIoc']);
        Route::post('/cases/{case}/status', [OsintCaseController::class, 'updateStatus']);
        Route::get('/cases/{case}/export', [OsintCaseController::class, 'export']);
    });
});
