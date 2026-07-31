<?php

return [
    // Force every user to set up TOTP two-factor before they can use the app.
    // SAFETY VALVE: set MFA_ENFORCED=false to lift enforcement instantly (without
    // a redeploy) if a rollout locks people out. Individual accounts can also be
    // reset with `php artisan 2fa:reset {email}`.
    'enforced' => filter_var(env('MFA_ENFORCED', true), FILTER_VALIDATE_BOOL),

    // Issuer label shown in the authenticator app (e.g. "Nodus (admin@…)").
    'issuer' => env('MFA_ISSUER', 'Nodus'),

    // How many one-time recovery codes to generate at confirmation.
    'recovery_codes' => 10,
];
