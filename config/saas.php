<?php

return [
    'enabled' => env('SAAS_ENABLED', false),
    'stripe_secret' => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'prices' => [
        'pass' => env('STRIPE_PRICE_PASS'),
        'studio_monthly' => env('STRIPE_PRICE_STUDIO_MONTHLY'),
        'studio_yearly' => env('STRIPE_PRICE_STUDIO_YEARLY'),
        'extra_event' => env('STRIPE_PRICE_EXTRA_EVENT'),
    ],
    'attendee_limit' => 100,
    'pass_duration_minutes' => 120,
    'pass_download_days' => 30,
    'studio_rooms_per_month' => 4,
    'studio_recordings' => 3,
];
