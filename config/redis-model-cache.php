<?php

declare(strict_types=1);

return [
    'connection' => env('REDIS_MODEL_CACHE_CONNECTION', 'cache'),

    'default_ttl' => env('REDIS_MODEL_CACHE_TTL', 86400),

    'scan_strategy' => env('REDIS_MODEL_CACHE_SCAN_STRATEGY', 'scan'),

    'scan_count' => env('REDIS_MODEL_CACHE_SCAN_COUNT', 1000),
];
