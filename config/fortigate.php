<?php

return [

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
        [
            'key' => 'dns-unreachable',
            'match' => '/dns[ _-]?server.{0,24}(unreachable|not responding|timed? ?out|failure)/i',
            'severity' => 'critical',
            'description' => 'DNS server unreachable',
        ],
        [
            'key' => 'dns-failover',
            'match' => '/(switched|failover|fail(ed)? over).{0,24}dns[ _-]?server/i',
            'severity' => 'warning',
            'description' => 'DNS failed over to a secondary server',
        ],
        [
            'key' => 'fortiguard-unreachable',
            'match' => '/fortiguard.{0,40}(unreachable|cannot connect|connection failed|no server|not responding)/i',
            'severity' => 'critical',
            'description' => 'FortiGuard servers unreachable — rating and signature lookups are failing',
        ],
        [
            'key' => 'rating-error',
            'match' => '/(rating|categor(y|isation|ization)).{0,24}(error|fail|timeout)/i',
            'severity' => 'warning',
            'description' => 'Web/DNS filter rating lookups are failing — traffic is being passed unrated',
        ],
        [
            'key' => 'dnsfilter-failure',
            'match' => '/dns[ _-]?filter.{0,30}(fail|error|unavailable|bypass)/i',
            'severity' => 'warning',
            'description' => 'DNS filter is failing or being bypassed',
        ],
        [
            'key' => 'webfilter-failure',
            'match' => '/web ?filter.{0,30}(fail|error|unavailable|disabled unexpectedly)/i',
            'severity' => 'warning',
            'description' => 'Web filter is failing',
        ],
        [
            'key' => 'update-failed',
            'match' => '/(update|signature|definition).{0,30}(failed|failure|could not|unable to)/i',
            'severity' => 'warning',
            'description' => 'FortiGuard signature update failed — AV/IPS definitions are going stale',
        ],
        [
            'key' => 'license-expired',
            'match' => '/(licen[cs]e|contract|subscription).{0,30}(expired|invalid|not valid)/i',
            'severity' => 'critical',
            'description' => 'A FortiGuard subscription has expired — the filters it feeds are no longer updating',
        ],
        [
            'key' => 'conserve-mode',
            'match' => '/(enter(ed|ing)?|in) conserve mode/i',
            'severity' => 'critical',
            'description' => 'Firewall entered conserve mode — it is refusing new sessions',
        ],
        [
            'key' => 'ha-failover',
            'match' => '/ha .{0,20}(failover|member (left|joined)|state changed)/i',
            'severity' => 'critical',
            'description' => 'HA failover or cluster membership change',
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
