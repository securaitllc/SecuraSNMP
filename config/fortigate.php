<?php

return [

    /*
    |---------------------------------------------------------------------------
    | State-derived checks
    |---------------------------------------------------------------------------
    |
    | Each SNMP check can be turned off without a deploy. This exists because the
    | HA check shipped enabled against OIDs that had never been read on real
    | hardware, and a standalone FGT60E — `config system ha` with nothing set —
    | answered fgHaStatsSyncStatus with a single row reading 0. Two firewalls
    | raised a critical "cluster member out of sync" for a cluster that does not
    | exist. The check is fixed, but a false critical on a security device should
    | be one env var away from silence, not one release.
    |
    | Confirm what a given firewall actually answers with:
    |   php artisan fortigate:probe --all
    |
    */

    'checks' => [
        'vpn' => (bool) env('FORTIGATE_CHECK_VPN', true),
        'ha' => (bool) env('FORTIGATE_CHECK_HA', true),
        'memory' => (bool) env('FORTIGATE_CHECK_MEMORY', true),
        'disk' => (bool) env('FORTIGATE_CHECK_DISK', true),
        'cpu' => (bool) env('FORTIGATE_CHECK_CPU', true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Log-derived alarms
    |---------------------------------------------------------------------------
    |
    | A FortiGate reports DNS and UTM trouble in its LOG, not in any SNMP table.
    | There is no OID that says "the DNS filter cannot reach FortiGuard" — the box
    | writes a log line and carries on, so the only way to alarm on it is to read
    | what it writes. That means the firewall has to send syslog to Nodus:
    |
    |   config log syslogd setting
    |       set status enable
    |       set server <nodus-ip>
    |       set port 514
    |       set facility local7
    |       set format default
    |   end
    |   config log syslogd filter
    |       set severity warning
    |   end
    |
    | Until that is configured these rules simply never match — they raise nothing
    | and cost nothing.
    |
    | Each rule is matched (case-insensitively) against the message text. `key`
    | becomes the alarm id, so all occurrences of one condition collapse into a
    | single alarm rather than one per log line. Tune freely: this is config
    | precisely so a noisy rule does not need a deploy to silence.
    |
    */

    'log_rules' => [
        // Each rule matches ONE named FortiOS field. `field` defaults to logdesc,
        // which is FortiOS's own canonical name for the event and therefore the only
        // thing precise enough to alarm on. Matching the raw line let a pattern
        // straddle two unrelated fields: a FortiAnalyzer failure carrying
        // reason="...data updated for reconnecting" msg="Failed to ..." was read as a
        // FortiGuard signature failure on a firewall whose signatures were fine.
        //
        // The two marked OBSERVED were taken from real Massey firewall logs. The rest
        // are written against documented FortiOS wording and have not been seen here
        // yet — they will match when the condition occurs, and should be corrected
        // against a real line the first time one does.

        // ── DNS ──────────────────────────────────────────────────────────────
        [
            'key' => 'dns-unreachable',
            'match' => '/^DNS server.*(unreachable|not responding|failure)/i',
            'severity' => 'critical',
            'description' => 'DNS server unreachable',
        ],

        // ── FortiGuard, rating and the UTM filters ───────────────────────────
        [
            'key' => 'fortiguard-unreachable',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/FortiGuard.*(unreachable|cannot connect|connection fail|no server)/i',
            'severity' => 'critical',
            'description' => 'FortiGuard servers unreachable — rating and signature lookups are failing',
        ],
        [
            'key' => 'rating-error',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/(rating|categor)\\w*\\s*(error|fail|timeout)/i',
            'severity' => 'warning',
            'description' => 'Web/DNS filter rating lookups are failing — traffic is being passed unrated',
        ],
        [
            'key' => 'dnsfilter-failure',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/DNS ?filter.*(fail|error|unavailable|bypass)/i',
            'severity' => 'warning',
            'description' => 'DNS filter is failing or being bypassed',
        ],
        [
            'key' => 'webfilter-failure',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/Web ?filter.*(fail|error|unavailable)/i',
            'severity' => 'warning',
            'description' => 'Web filter is failing',
        ],
        [
            'key' => 'update-failed',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/^(FortiGuard )?(AV|IPS|antivirus|signature|definition|update).*(fail|error)/i',
            'severity' => 'warning',
            'description' => 'FortiGuard signature update failed — AV/IPS definitions are going stale',
        ],
        [
            'key' => 'license-expired',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/(licen[cs]e|contract|subscription).*(expired|invalid|not valid)/i',
            'severity' => 'critical',
            'description' => 'A FortiGuard subscription has expired — the filters it feeds are no longer updating',
        ],

        // ── The box itself ───────────────────────────────────────────────────
        [
            'key' => 'conserve-mode',
            // Detail for this one can arrive in msg rather than logdesc depending on
            // the subtype; both are read, each on its own.
            'field' => ['logdesc', 'msg'],
            'match' => '/conserve mode/i',
            'severity' => 'critical',
            'description' => 'Firewall entered conserve mode — it is refusing new sessions',
        ],
        [
            'key' => 'ha-failover',
            'match' => '/^HA .*(failover|member|state)/i',
            'severity' => 'critical',
            'description' => 'HA failover or cluster membership change',
        ],

        // ── OBSERVED on this fleet ───────────────────────────────────────────
        // Real, recurring, and previously mislabelled as a signature failure. The
        // firewall keeps forwarding traffic; what stops is the logging being stored,
        // so an incident afterwards has no record to reconstruct from.
        [
            'key' => 'fortianalyzer-down',
            'match' => '/^FortiAnalyzer connection failed/i',
            'severity' => 'warning',
            'description' => 'FortiAnalyzer connection failed — traffic is still forwarding but the firewall logs are not being stored',
        ],
        // The Azure firewall's SDN connector reads the fabric to resolve dynamic
        // address objects. When the API fails those objects stop updating, and a
        // policy that still looks correct starts matching the wrong addresses.
        [
            'key' => 'sdn-connector-failed',
            'match' => '/^SDN Connector .*(fail|error)/i',
            'severity' => 'warning',
            'description' => 'SDN Connector API failed — dynamic address objects are no longer resolving',
        ],
    ],

    /*
    | A log line is an EVENT, not a state — the firewall says "DNS is unreachable"
    | once, not continuously. So a log-derived alarm cannot be cleared by the
    | condition disappearing from a table; it is cleared when it stops recurring
    | for this long. Long enough that a genuinely broken resolver keeps the alarm
    | up between retries, short enough that a one-off does not sit there all day.
    */
    'log_alarm_quiet_minutes' => (int) env('FORTIGATE_LOG_ALARM_QUIET_MINUTES', 30),

];
