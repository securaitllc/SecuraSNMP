<?php

return [
    // Pull the live NVD feed on each vuln:refresh cycle. When false (or the host has
    // no outbound internet), the assessment runs against the existing catalog and the
    // starter set is used on first boot.
    'feed_enabled' => env('VULN_FEED_ENABLED', true),

    // Optional NVD API key — raises the rate limit from 5 to 50 requests / 30s.
    // https://nvd.nist.gov/developers/request-an-api-key
    'nvd_api_key' => env('NVD_API_KEY'),

    // Pause between NVD page requests to respect the rate limit. ~6s without a key,
    // ~0.6s with one.
    'nvd_delay_ms' => env('NVD_DELAY_MS', env('NVD_API_KEY') ? 700 : 6000),
];
