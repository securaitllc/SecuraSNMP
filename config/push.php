<?php

/*
 * Web Push (VAPID). Generate a key pair once with `php artisan push:vapid-keys`
 * and set the three values in the environment. With no private key configured
 * push is simply off: subscriptions are refused and nothing is sent.
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:noc@example.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
    // Seconds the push service may hold an undelivered alarm for a phone that is
    // off the network. Long enough to survive a tunnel, short enough that a stale
    // "down" never lands after the recovery already did.
    'ttl' => (int) env('PUSH_TTL', 900),
];
