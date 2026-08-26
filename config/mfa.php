<?php

return [
    // Global master switch. When true, EVERY user is forced into two-factor
    // (blanket policy). Default OFF — two-factor is opt-in PER USER via the
    // users.mfa_required toggle instead, so a rollout can't lock everyone out.
    // Break-glass for a single account: `php artisan 2fa:reset {email}`.
    'enforced' => filter_var(env('MFA_ENFORCED', false), FILTER_VALIDATE_BOOL),

    // Issuer label shown in the authenticator app (e.g. "Nodus (admin@…)").
    'issuer' => env('MFA_ISSUER', 'Nodus'),

    // Non-standard `image` param appended to the otpauth URI. Apps that support it
    // (2FAS, Aegis, …) show this logo next to the account; ones that don't (Google
    // Authenticator) simply ignore it. Must be a public HTTPS image URL.
    'logo_url' => env('MFA_LOGO_URL', 'https://securait.net/nodus-logo.png'),

    // How many one-time recovery codes to generate at confirmation.
    'recovery_codes' => 10,
];
