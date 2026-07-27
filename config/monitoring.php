<?php

// Poll intervals (seconds) for the continuous monitors. Defaults keep the
// original 5-minute cadence; lower them (e.g. 60–90s) on WAN-critical edges for
// faster detection/clear, at the cost of more SSH/SNMP load. Floored at 30s.
return [

    // Ticket numbers are <org>-<type>-<sequence>, e.g. MSIT-ALM-000123. The org
    // prefix is configurable because this ships to more than one customer; the
    // type segment lets an operator tell an alarm from an interface or tunnel
    // ticket without looking it up.
    'ticket_prefix' => env('TICKET_PREFIX', 'MSIT'),
    // Consecutive missed ICMP polls before a device is declared DOWN (critical
    // alarm). At the 60s device-poll cadence, 3 ≈ a 3-minute debounce.
    'down_threshold' => (int) env('DEVICE_DOWN_POLLS', 3),

    'nexthop_interval' => (int) env('POLL_NEXTHOP_SECONDS', 300),
    'interface_interval' => (int) env('POLL_INTERFACE_SECONDS', 300),
    'edgeconnect_interval' => (int) env('POLL_EDGECONNECT_SECONDS', 300),

    // EdgeConnect ALARM reconciliation runs on its own fast loop (raise/clear
    // latency), separate from the heavier 5-minute health poll. Default 90s.
    'edgeconnect_alarm_interval' => (int) env('POLL_EDGECONNECT_ALARM_SECONDS', 90),
];
