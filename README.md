# PivotPHP ReactPHP Extension

[![Latest Stable Version](https://poser.pugx.org/pivotphp/reactphp/v/stable)](https://packagist.org/packages/pivotphp/reactphp)
[![Total Downloads](https://poser.pugx.org/pivotphp/reactphp/downloads)](https://packagist.org/packages/pivotphp/reactphp)
[![License](https://poser.pugx.org/pivotphp/reactphp/license)](https://packagist.org/packages/pivotphp/reactphp)

A high-performance continuous runtime extension for PivotPHP using ReactPHP's event-driven, non-blocking I/O model.

**Current Version: 0.0.1** - [View on Packagist](https://packagist.org/packages/pivotphp/reactphp)

## Features

- **Continuous Runtime**: Keep your application in memory between requests
- **Event-Driven Architecture**: Non-blocking I/O for handling concurrent requests
- **PSR-7 Compatible**: Full compatibility with PivotPHP's PSR-7 implementation
- **High Performance**: Eliminate bootstrap overhead for each request
- **Async Support**: Built-in support for promises and async operations
- **WebSocket Ready**: Foundation for real-time communication (future feature)

## Installation

```bash
composer require pivotphp/reactphp
```

## Quick Start

### Basic Server

```php
<?php
use PivotPHP\Core\Application;
use PivotPHP\ReactPHP\Providers\ReactPHPServiceProvider;
use PivotPHP\ReactPHP\Server\ReactServer;

$app = new Application(__DIR__);
$app->register(new ReactPHPServiceProvider());

// Define your routes
$router = $app->get('router');
$router->get('/', fn() => Response::json(['message' => 'Hello, ReactPHP!']));

// Start the server
$server = $app->get(ReactServer::class);
$server->listen('0.0.0.0:8080');
```

### Using the Console Command

```bash
# Start server with default settings
php artisan serve:reactphp

# Custom host and port
php artisan serve:reactphp --host=127.0.0.1 --port=8000

# Development mode
php artisan serve:reactphp --env=development
```

### Running Examples

```bash
# Basic server example
php examples/server.php

# Async features example
php examples/async-example.php
```

## Configuration

Create `config/reactphp.php`:

```php
return [
    'server' => [
        'debug' => env('APP_DEBUG', false),
        'streaming' => env('REACTPHP_STREAMING', false),
        'max_concurrent_requests' => env('REACTPHP_MAX_CONCURRENT_REQUESTS', 100),
        'request_body_size_limit' => env('REACTPHP_REQUEST_BODY_SIZE_LIMIT', 67108864), // 64MB
        'request_body_buffer_size' => env('REACTPHP_REQUEST_BODY_BUFFER_SIZE', 8192), // 8KB
    ],
];
```

## Async Operations

ReactPHP enables true asynchronous operations:

```php
use React\Promise\Promise;
use React\Http\Browser;

$router->get('/async/fetch', function () use ($browser): Promise {
    return $browser->get('https://api.example.com/data')->then(
        fn($response) => Response::json(json_decode((string) $response->getBody()))
    );
});
```

## Performance Benefits

- **Persistent Application State**: No need to bootstrap the application for each request
- **Reduced Memory Allocation**: Reuse objects across requests
- **Connection Pooling**: Keep database connections alive
- **Faster Response Times**: Eliminate framework initialization overhead

## Middleware Support

All PivotPHP middleware works seamlessly:

```php
$app->addGlobalMiddleware(function ($request, $next) {
    $start = microtime(true);
    $response = $next($request);
    $duration = round((microtime(true) - $start) * 1000, 2);
    
    return $response->withHeader('X-Response-Time', $duration . 'ms');
});
```

## Production Deployment

### Process Management

Use a process manager like Supervisor:

```ini
[program:pivotphp-reactphp]
command=php /path/to/app/artisan serve:reactphp --port=8080
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/pivotphp-reactphp.log
```

### Nginx Proxy

```nginx
upstream pivotphp_backend {
    server 127.0.0.1:8080;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    server 127.0.0.1:8083;
}

server {
    listen 80;
    server_name example.com;

    location / {
        proxy_pass http://pivotphp_backend;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Testing

```bash
# Run tests
composer test

# With coverage
composer test:coverage

# Quality checks
composer quality:check
```

## Benchmarking

Compare performance with traditional PHP-FPM:

```bash
# ReactPHP server
ab -n 10000 -c 100 http://localhost:8080/

# Traditional PHP-FPM
ab -n 10000 -c 100 http://localhost/
```

## Limitations

- Long-running processes require careful memory management
- Some PHP extensions may not be compatible with async operations
- Global state must be handled carefully
- File uploads are buffered in memory by default

## Future Features

- WebSocket support for real-time communication
- HTTP/2 and HTTP/3 support
- Built-in clustering for multi-core utilization
- GraphQL subscriptions support
- Server-sent events (SSE)

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.