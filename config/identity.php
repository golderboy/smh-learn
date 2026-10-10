<?php

declare(strict_types=1);

return [
    'product_name' => 'SMH Learning',
    'initial_password' => env('INITIAL_USER_PASSWORD', 'smh123456'),
    'require_initial_password_change' => env('REQUIRE_INITIAL_PASSWORD_CHANGE', true),
    'thaid' => [
        // A transport scaffold exists; only a production-accepted verifier can remove this gate.
        'adapter_ready' => false,
        'enabled' => env('THAID_ENABLED', false),
        'issuer' => env('THAID_ISSUER'),
        'client_id' => env('THAID_CLIENT_ID'),
        'client_secret' => env('THAID_CLIENT_SECRET'),
        'redirect_uri' => env('THAID_REDIRECT_URI'),
        'manual_version' => '1.1.0',
    ],
];
