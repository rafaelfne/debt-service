<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Runtime business configuration
|--------------------------------------------------------------------------
|
| Single source of truth for every "knob" the evaluator might want to tweak
| during a live demo without touching code. All defaults reflect the canon
| values from the enunciado — leaving the corresponding `.env` var unset
| keeps the byte-for-byte canonical output.
|
| Domain values stay as strings to feed directly into Money::of / BigDecimal,
| preserving full decimal precision (CLAUDE.md §4 #1).
|
*/

return [
    'interest' => [
        'ipva' => [
            'daily_rate' => env('IPVA_DAILY_RATE', '0.0033'),
            'cap_rate' => env('IPVA_CAP_RATE', '0.20'),
        ],
        'multa' => [
            'daily_rate' => env('MULTA_DAILY_RATE', '0.01'),
        ],
    ],

    'payment' => [
        'pix' => [
            'discount_factor' => env('PIX_DISCOUNT_FACTOR', '0.95'),
        ],
        'credit_card' => [
            'monthly_rate' => env('CC_MONTHLY_RATE', '0.025'),
        ],
    ],

    'providers' => [
        'a' => [
            'url' => env('PROVIDER_A_URL'),
            'timeout' => (int) env('PROVIDER_A_TIMEOUT', 2),
            'retries' => (int) env('PROVIDER_A_RETRIES', 3),
            'backoff_ms' => (int) env('PROVIDER_A_BACKOFF_MS', 100),
        ],
        'b' => [
            'url' => env('PROVIDER_B_URL'),
            'timeout' => (int) env('PROVIDER_B_TIMEOUT', 2),
            'retries' => (int) env('PROVIDER_B_RETRIES', 3),
            'backoff_ms' => (int) env('PROVIDER_B_BACKOFF_MS', 100),
        ],
    ],

    'resilience' => [
        'chain_budget_seconds' => (float) env('CHAIN_BUDGET_SECONDS', 5.0),
        'circuit_breaker' => [
            'failure_threshold' => (int) env('CB_FAILURE_THRESHOLD', 5),
            'cooldown_seconds' => (float) env('CB_COOLDOWN_SECONDS', 30.0),
        ],
    ],

    'http' => [
        'max_body_bytes' => (int) env('HTTP_MAX_BODY_BYTES', 1_048_576),
    ],
];
