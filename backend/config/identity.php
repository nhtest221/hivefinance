<?php

return [
    'lockout' => [
        'max_attempts' => env('AUTH_LOCKOUT_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('AUTH_LOCKOUT_DECAY_MINUTES', 15),
    ],

    'mfa' => [
        'challenge_ttl_minutes' => env('MFA_CHALLENGE_TTL_MINUTES', 5),
        'test_code' => env('MFA_TEST_CODE', '000000'),
    ],
];
