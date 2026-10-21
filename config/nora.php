<?php

return [
    // Lumen NORA — Network Operations Response Agent. An email-driven service agent:
    // open a repair ticket, run service checks, check status, escalate, close — all by
    // email, threaded. Replies come back in the same thread with the ticket number.
    'enabled' => (bool) env('NORA_ENABLED', false),

    'address' => env('NORA_ADDRESS', 'NORA@lumen.com'),

    // NORA only acts on mail from an address registered in Lumen SMCM. A human does
    // that registration ONCE from this mailbox; every message Nodus sends is from it.
    'from_address' => env('NORA_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
    'from_name' => env('NORA_FROM_NAME', 'Massey Services NOC'),

    // Carrier names on circuits that route to NORA. Matched case-insensitively.
    'carriers' => ['lumen', 'level3', 'level 3', 'centurylink'],

    // Advisory gates shown on the preview. Phase 1 is human-in-the-loop, so they warn
    // rather than block — but the breaker warning is the one that matters: NORA filters
    // "suspicious" senders, and a burst of tickets from one address would get this
    // mailbox flagged, after which nothing from Nodus reaches Lumen at all.
    'sustained_minutes' => (int) env('NORA_SUSTAINED_MINUTES', 10),
    'breaker_pct' => (int) env('NORA_BREAKER_PCT', 5),
    'breaker_min' => (int) env('NORA_BREAKER_MIN', 3),
];
