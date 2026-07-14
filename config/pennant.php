<?php

return [

    // Feature flags let Phase 2/3 modules (rewards, referrals, affiliates,
    // automatic debit, agent network, cooperative savings) ship dark and be
    // enabled per environment/cohort without a deploy. See
    // app/Shared/Features.php for the defined flags and
    // docs/firstmarket_Implementation_Plan.md section 1.2.
    'default' => env('PENNANT_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
        ],

    ],

];
