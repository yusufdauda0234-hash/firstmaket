<?php

return [

    // Feature flags let Phase 2/3 modules (rewards, referrals, affiliates,
    // automatic debit, agent network, cooperative savings) ship dark and be
    // enabled per environment/cohort without a deploy. See
    // app/Shared/Features.php for the defined flags and
    // docs/FirstMaket_Implementation_Plan.md section 1.2.
    // Runtime state is owned by App\Models\Setting; Pennant only evaluates
    // the registered definitions and must not require a second feature table.
    'default' => env('PENNANT_STORE', 'array'),

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
