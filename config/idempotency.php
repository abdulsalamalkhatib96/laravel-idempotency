<?php

return [
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),
    'secret' => env('IDEMPOTENCY_SECRET', env('APP_KEY')),
    'namespace' => env('IDEMPOTENCY_NAMESPACE', env('APP_NAME', 'laravel')),
    'default_profile' => env('IDEMPOTENCY_PROFILE', 'default'),
    'middleware_alias' => env('IDEMPOTENCY_MIDDLEWARE_ALIAS', 'idempotency'),
    'key_max_length' => 255,
    'fingerprint_version' => 'v1',

    'profiles' => [
        'default' => [
            'store' => 'database',
            'lock_store' => env('IDEMPOTENCY_LOCK_STORE', env('CACHE_STORE', 'database')),
            'ttl' => 86_400,
            'processing_lease' => 120,
            'lock_seconds' => 10,
            'lock_wait_seconds' => 3,
            'concurrent' => 'conflict', // conflict|wait
            'concurrent_wait_seconds' => 3,
            'orphan_policy' => 'indeterminate', // indeterminate|safe_retry
            'require_principal' => false,
            'encrypt_response' => false,
            'max_response_bytes' => 2_097_152,
        ],

        'financial' => [
            'store' => 'database',
            'lock_store' => env('IDEMPOTENCY_LOCK_STORE', 'redis'),
            'ttl' => 604_800,
            'processing_lease' => 180,
            'lock_seconds' => 10,
            'lock_wait_seconds' => 5,
            'concurrent' => 'conflict',
            'concurrent_wait_seconds' => 5,
            'orphan_policy' => 'indeterminate',
            'require_principal' => true,
            'encrypt_response' => true,
            'max_response_bytes' => 2_097_152,
        ],

        'webhook' => [
            'store' => 'database',
            'lock_store' => env('IDEMPOTENCY_LOCK_STORE', env('CACHE_STORE', 'database')),
            'ttl' => 604_800,
            'processing_lease' => 120,
            'lock_seconds' => 10,
            'lock_wait_seconds' => 3,
            'concurrent' => 'conflict',
            'concurrent_wait_seconds' => 3,
            'orphan_policy' => 'indeterminate',
            'require_principal' => false,
            'encrypt_response' => false,
            'max_response_bytes' => 2_097_152,
        ],
    ],

    'database' => [
        'connection' => env('IDEMPOTENCY_DB_CONNECTION'),
        'table' => 'idempotency_records',
    ],

    'redis' => [
        'cache_store' => env('IDEMPOTENCY_REDIS_CACHE_STORE', 'redis'),
        'prefix' => 'idempotency:v1:',
    ],

    'fingerprint' => [
        'include_query' => true,
        'include_route_parameters' => true,
        'include_files' => true,
        'include_headers' => [],
        'ignored_body_fields' => [],
        'ignored_query_fields' => [],
    ],

    'response' => [
        'header_whitelist' => [
            'content-type',
            'content-language',
            'location',
            'cache-control',
            'etag',
        ],
    ],

    'tenant' => [
        'header' => null,
    ],

    'cleanup' => [
        'enabled' => true,
        'batch_size' => 1000,
    ],
];
