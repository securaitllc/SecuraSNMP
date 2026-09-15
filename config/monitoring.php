<?php

// Poll intervals (seconds) for the continuous monitors. Defaults keep the
// original 5-minute cadence; lower them (e.g. 60–90s) on WAN-critical edges for
// faster detection/clear, at the cost of more SSH/SNMP load. Floored at 30s.
return [
    // Consecutive missed ICMP polls before a device is declared DOWN (critical
    // alarm). down_threshold × device_interval = detection time. Defaults 3 × 30s =
    // a 90s debounce — fast enough to catch a reboot, still 3 misses so one dropped
    // ping on a flaky WAN link doesn't false-alarm. Tune per network tolerance.
    'down_threshold' => (int) env('DEVICE_DOWN_POLLS', 3),

    // How often each device is pinged (parallel pool, cheap). Lower = faster
    // device-down detection + clear, more ping rows. Floored at 15s.
    'device_interval' => (int) env('DEVICE_POLL_SECONDS', 30),

    // How many devices health:monitor polls concurrently (a subprocess pool). Higher
    // = faster full sweep, more transient memory (~40MB per PHP subprocess). Lower it
    // on a memory-constrained host.
    'health_concurrency' => (int) env('HEALTH_CONCURRENCY', 10),

    // How many devices the interface sweep polls at once (interfaces:poll-device pool).
    // Higher = a faster full sweep, more transient memory (~40MB per PHP subprocess) and
    // SNMP load. 20 keeps the whole fleet's interfaces fresh within ~1 cycle; lower it if
    // the host is memory-constrained, raise it (INTERFACE_CONCURRENCY) for even faster.
    'interface_concurrency' => (int) env('INTERFACE_CONCURRENCY', 20),

    // How many EdgeConnect appliances the SSH sweeps (nexthops:poll + edgeconnect:verify)
    // poll at once. Deliberately CONSERVATIVE — SSH sessions (phpseclib) are heavier than
    // an snmpwalk, and the two SSH sweeps run simultaneously, so 6 keeps their combined
    // subprocess memory modest while still collapsing the ~260s sequential sweep to well
    // under the stale threshold. Raise SSH_CONCURRENCY only if the host has headroom.
    'ssh_concurrency' => (int) env('SSH_CONCURRENCY', 6),

    'nexthop_interval' => (int) env('POLL_NEXTHOP_SECONDS', 300),
    'interface_interval' => (int) env('POLL_INTERFACE_SECONDS', 300),
    'edgeconnect_interval' => (int) env('POLL_EDGECONNECT_SECONDS', 300),

    // EdgeConnect ALARM reconciliation runs on its own fast loop (raise/clear
    // latency), separate from the heavier 5-minute health poll. Default 90s.
    'edgeconnect_alarm_interval' => (int) env('POLL_EDGECONNECT_ALARM_SECONDS', 90),
];
