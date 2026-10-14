<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],
    // Pinned to the verbs the SPA actually uses — not '*'. With supports_credentials
    // true, a permissive method/header set widens the blast radius of any origin
    // misconfiguration; the boot-time guard in AppServiceProvider refuses to run with
    // a '*' origin while credentials are enabled.
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:8000')),
    'allowed_origins_patterns' => [],
    // The exact headers the SPA sends (Sanctum CSRF + JSON). No '*'.
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Authorization'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
