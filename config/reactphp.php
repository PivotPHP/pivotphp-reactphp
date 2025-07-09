<?php

declare(strict_types=1);

return [
    'server' => [
        'debug' => env('APP_DEBUG', false),
        'streaming' => env('REACTPHP_STREAMING', false),
        'max_concurrent_requests' => env('REACTPHP_MAX_CONCURRENT_REQUESTS', 100),
        'request_body_size_limit' => env('REACTPHP_REQUEST_BODY_SIZE_LIMIT', 67108864), // 64MB
        'request_body_buffer_size' => env('REACTPHP_REQUEST_BODY_BUFFER_SIZE', 8192), // 8KB
    ],
    
    'middleware' => [
        // Add ReactPHP-specific middleware here
    ],
    
    'loop' => [
        'timer_interval' => env('REACTPHP_TIMER_INTERVAL', 0.001),
        'future_tick_queue_limit' => env('REACTPHP_FUTURE_TICK_QUEUE_LIMIT', 1000),
    ],
    
    'performance' => [
        'enable_profiling' => env('REACTPHP_ENABLE_PROFILING', false),
        'profile_memory' => env('REACTPHP_PROFILE_MEMORY', false),
        'gc_collect_cycles_interval' => env('REACTPHP_GC_INTERVAL', 1000),
    ],
];