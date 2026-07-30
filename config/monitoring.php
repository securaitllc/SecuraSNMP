<?php

// Poll intervals (seconds) for the continuous monitors. Defaults keep the
// original 5-minute cadence; lower them (e.g. 60–90s) on WAN-critical edges for
// faster detection/clear, at the cost of more SSH/SNMP load. Floored at 30s.
return [
    // Consecutive missed ICMP polls before a device is declared DOWN (critical
    // alarm). At the 60s device-poll cadence, 3 ≈ a 3-minute debounce.
    'down_threshold' => (int) env('DEVICE_DOWN_POLLS', 3),

    // How many devices health:monitor polls concurrently (a subprocess pool). Higher
    // = faster full sweep, more transient memory (~40MB per PHP subprocess). Lower it
    // on a memory-constrained host.
    'health_concurrency' => (int) env('HEALTH_CONCURRENCY', 10),

    'nexthop_interval' => (int) env('POLL_NEXTHOP_SECONDS', 300),
    'interface_interval' => (int) env('POLL_INTERFACE_SECONDS', 300),
    'edgeconnect_interval' => (int) env('POLL_EDGECONNECT_SECONDS', 300),

    // EdgeConnect ALARM reconciliation runs on its own fast loop (raise/clear
    // latency), separate from the heavier 5-minute health poll. Default 90s.
    'edgeconnect_alarm_interval' => (int) env('POLL_EDGECONNECT_ALARM_SECONDS', 90),
];
