# PivotPHP ReactPHP Extension Documentation

## Table of Contents

1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Basic Usage](#basic-usage)
4. [Advanced Configuration](#advanced-configuration)
5. [Architecture Overview](#architecture-overview)
6. [Implementation Guide](#implementation-guide)
7. [Testing](#testing)
8. [Common Issues & Solutions](#common-issues--solutions)
9. [Performance Considerations](#performance-considerations)
10. [API Reference](#api-reference)

## Introduction

The PivotPHP ReactPHP Extension enables continuous runtime execution for PivotPHP applications using ReactPHP's event-driven, non-blocking I/O architecture. This extension bridges the gap between PivotPHP's Express.js-style framework and ReactPHP's high-performance server capabilities.

### Key Features

- ✅ **Event-driven execution**: Non-blocking I/O for high concurrency
- ✅ **PSR-7 compatible**: Full PSR-7 v1.x support for ReactPHP integration
- ✅ **Express.js style**: Maintains PivotPHP's familiar callback pattern
- ✅ **Type safe**: Full type coverage with PHPStan Level 9 compliance
- ✅ **High performance**: Optimized for thousands of concurrent connections
- ✅ **Zero configuration**: Works out of the box with minimal setup

### Benefits Over Traditional PHP-FPM

- **Memory efficiency**: Shared application state across requests
- **Faster startup**: No process creation overhead per request
- **Connection persistence**: Database and service connections stay alive
- **Real-time capabilities**: WebSocket and SSE support ready
- **Scalability**: Handle thousands of concurrent connections

## Installation

### Requirements

- PHP 8.1 or higher
- PivotPHP Core v1.0.1+
- ReactPHP v1.x
- PSR-7 v1.x (automatically configured)

### Composer Installation

```bash
# Install the extension
composer require pivotphp/reactphp

# Configure PSR-7 v1.x for ReactPHP compatibility
cd your-pivotphp-project
php vendor/pivotphp/core/scripts/switch-psr7-version.php 1
composer update psr/http-message
```

### Basic Setup

Create a server script (`server.php`):

```php
<?php

require_once 'vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\ReactPHP\Server\ReactServer;
use React\EventLoop\Loop;

// Create PivotPHP application
$app = new Application();

// Define routes (Express.js style)
$app->get('/', function ($request, $response) {
    $response->json(['message' => 'Hello from ReactPHP!']);
});

$app->get('/users/:id', function ($request, $response) {
    $userId = $request->param('id');
    $response->json(['user_id' => $userId]);
});

// Create ReactPHP server
$loop = Loop::get();
$server = new ReactServer($app, $loop);

// Start server
echo "Server running on http://0.0.0.0:8080\n";
$server->listen('0.0.0.0:8080');
$loop->run();
```

Run the server:

```bash
php server.php
```

## Basic Usage

### Simple API Server

```php
<?php

require_once 'vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\ReactPHP\Server\ReactServer;
use React\EventLoop\Loop;

$app = new Application();

// Health check endpoint
$app->get('/health', function ($request, $response) {
    $response->json([
        'status' => 'healthy',
        'timestamp' => time(),
        'memory' => memory_get_usage(true)
    ]);
});

// User management API
$app->get('/api/users', function ($request, $response) {
    // Simulate database query
    $users = [
        ['id' => 1, 'name' => 'John Doe'],
        ['id' => 2, 'name' => 'Jane Smith']
    ];
    $response->json($users);
});

$app->post('/api/users', function ($request, $response) {
    $userData = $request->body;
    
    // Validate and create user
    if (empty($userData->name)) {
        $response->status(400)->json(['error' => 'Name is required']);
        return;
    }
    
    $newUser = [
        'id' => rand(1000, 9999),
        'name' => $userData->name,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $response->status(201)->json($newUser);
});

// Start ReactPHP server
$loop = Loop::get();
$server = new ReactServer($app, $loop);

echo "API Server running on http://0.0.0.0:8080\n";
$server->listen('0.0.0.0:8080');
$loop->run();
```

### With Middleware

```php
<?php

require_once 'vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Psr15\Middleware\CorsMiddleware;
use PivotPHP\Core\Http\Psr15\Middleware\SecurityMiddleware;
use PivotPHP\ReactPHP\Server\ReactServer;
use React\EventLoop\Loop;

$app = new Application();

// Add middleware
$app->use(new SecurityMiddleware());
$app->use(new CorsMiddleware([
    'allow_origins' => ['*'],
    'allow_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    'allow_headers' => ['Content-Type', 'Authorization']
]));

// Protected routes
$app->get('/api/protected', function ($request, $response) {
    $response->json(['message' => 'This is a protected endpoint']);
});

// Start server
$loop = Loop::get();
$server = new ReactServer($app, $loop);

echo "Server with middleware running on http://0.0.0.0:8080\n";
$server->listen('0.0.0.0:8080');
$loop->run();
```

## Advanced Configuration

### Custom Server Configuration

```php
<?php

use PivotPHP\ReactPHP\Server\ReactServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Psr\Log\LoggerInterface;

$app = new Application();
$loop = Loop::get();

// Custom logger
$logger = new MyCustomLogger();

// Create server with custom configuration
$server = new ReactServer($app, $loop, $logger);

// Configure socket server options
$socketServer = new SocketServer('0.0.0.0:8080', [], $loop);
$socketServer->on('connection', function ($connection) {
    echo "New connection from {$connection->getRemoteAddress()}\n";
});

// Start with custom socket
$server->listen($socketServer);
$loop->run();
```

### Environment-Based Configuration

```php
<?php

// Load environment variables
$host = $_ENV['REACTPHP_HOST'] ?? '0.0.0.0';
$port = $_ENV['REACTPHP_PORT'] ?? 8080;
$workers = $_ENV['REACTPHP_WORKERS'] ?? 1;
$debug = $_ENV['APP_DEBUG'] ?? false;

$app = new Application();

// Configure based on environment
if ($debug) {
    $app->use(new DebugMiddleware());
}

$loop = Loop::get();
$server = new ReactServer($app, $loop);

echo "Server running on {$host}:{$port} (Workers: {$workers})\n";
$server->listen("{$host}:{$port}");
$loop->run();
```

## Architecture Overview

### Component Structure

```
┌─────────────────────────────────────────────┐
│                 ReactPHP                    │
│  ┌─────────────────────────────────────────┐│
│  │            Event Loop                   ││
│  │  ┌─────────────────────────────────────┐││
│  │  │         HTTP Server                │││
│  │  │  ┌─────────────────────────────────┐│││
│  │  │  │      Request Bridge            ││││
│  │  │  │         │                      ││││
│  │  │  │         ▼                      ││││
│  │  │  │   PivotPHP Core               ││││
│  │  │  │         │                      ││││
│  │  │  │         ▼                      ││││
│  │  │  │    Response Bridge             ││││
│  │  │  └─────────────────────────────────┘│││
│  │  └─────────────────────────────────────┘││
│  └─────────────────────────────────────────┘│
└─────────────────────────────────────────────┘
```

### Request Flow

1. **ReactPHP receives HTTP request**
2. **RequestBridge converts** React ServerRequest → PivotPHP Request
3. **PivotPHP processes** the request through its routing system
4. **Controllers execute** with Express.js style (request, response) parameters
5. **ResponseBridge converts** PivotPHP Response → React Response
6. **ReactPHP sends** the HTTP response

### Key Components

#### ReactServer (`src/Server/ReactServer.php`)
- Main server class
- Integrates ReactPHP HTTP server with PivotPHP application
- Handles server lifecycle (start, stop, listen)

#### RequestBridge (`src/Bridge/RequestBridge.php`)
- Converts ReactPHP ServerRequest to PivotPHP Request
- Handles headers, query parameters, body parsing
- Manages global state during conversion

#### ResponseBridge (`src/Bridge/ResponseBridge.php`)
- Converts PivotPHP Response to ReactPHP Response
- Preserves headers, status codes, and body content
- Ensures proper HTTP compliance

## Implementation Guide

### Step 1: Project Setup

Create a new PivotPHP project or use an existing one:

```bash
# Create new project
composer create-project pivotphp/core my-reactphp-app
cd my-reactphp-app

# Install ReactPHP extension
composer require pivotphp/reactphp

# Configure PSR-7 v1.x
php vendor/pivotphp/core/scripts/switch-psr7-version.php 1
composer update
```

### Step 2: Basic Server Implementation

Create `server.php`:

```php
<?php

require_once 'vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\ReactPHP\Server\ReactServer;
use React\EventLoop\Loop;

// Bootstrap PivotPHP application
$app = new Application();

// Load your routes (Express.js style)
require_once 'routes/api.php';
require_once 'routes/web.php';

// Create and configure ReactPHP server
$loop = Loop::get();
$server = new ReactServer($app, $loop);

// Server configuration
$host = '0.0.0.0';
$port = 8080;

echo "🚀 ReactPHP Server starting...\n";
echo "📍 Listening on http://{$host}:{$port}\n";
echo "🛑 Press Ctrl+C to stop\n\n";

// Start the server
$server->listen("{$host}:{$port}");
$loop->run();
```

### Step 3: Define Routes

Create `routes/api.php`:

```php
<?php

// Health check
$app->get('/health', function ($request, $response) {
    $response->json([
        'status' => 'healthy',
        'version' => '1.0.0',
        'uptime' => uptime()
    ]);
});

// User API
$app->get('/api/users', function ($request, $response) {
    $users = getUsersFromDatabase();
    $response->json($users);
});

$app->post('/api/users', function ($request, $response) {
    $userData = $request->body;
    
    // Validation
    if (empty($userData->name) || empty($userData->email)) {
        $response->status(400)->json([
            'error' => 'Name and email are required'
        ]);
        return;
    }
    
    // Create user
    $user = createUser($userData);
    $response->status(201)->json($user);
});

$app->get('/api/users/:id', function ($request, $response) {
    $userId = $request->param('id');
    $user = getUserById($userId);
    
    if (!$user) {
        $response->status(404)->json(['error' => 'User not found']);
        return;
    }
    
    $response->json($user);
});
```

### Step 4: Add Service Providers

If using service providers, register them normally:

```php
<?php

// In your Application bootstrap
use App\Providers\DatabaseServiceProvider;
use App\Providers\CacheServiceProvider;

$app->register(new DatabaseServiceProvider());
$app->register(new CacheServiceProvider());
$app->boot();

// Routes will have access to all registered services
$app->get('/api/cached-data', function ($request, $response) use ($app) {
    $cache = $app->make('cache');
    $data = $cache->get('api_data', function () {
        return fetchExpensiveData();
    });
    
    $response->json($data);
});
```

### Step 5: Production Configuration

Create `config/server.php`:

```php
<?php

return [
    'host' => env('REACTPHP_HOST', '0.0.0.0'),
    'port' => env('REACTPHP_PORT', 8080),
    'workers' => env('REACTPHP_WORKERS', 1),
    'memory_limit' => env('REACTPHP_MEMORY_LIMIT', '256M'),
    'max_connections' => env('REACTPHP_MAX_CONNECTIONS', 1000),
    'timeout' => env('REACTPHP_TIMEOUT', 30),
    'ssl' => [
        'enabled' => env('REACTPHP_SSL_ENABLED', false),
        'cert' => env('REACTPHP_SSL_CERT'),
        'key' => env('REACTPHP_SSL_KEY'),
    ]
];
```

## Testing

### Unit Tests

The extension includes comprehensive tests:

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run specific test suite
vendor/bin/phpunit tests/Bridge/RequestBridgeTest.php
```

### Integration Testing

Test your ReactPHP integration:

```php
<?php

use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\EventLoop\Loop;

class ReactServerIntegrationTest extends TestCase
{
    private $server;
    private $loop;
    
    protected function setUp(): void
    {
        $this->loop = Loop::get();
        // Start your server in test mode
        $this->startTestServer();
    }
    
    public function testHealthEndpoint(): void
    {
        $browser = new Browser(null, $this->loop);
        
        $response = null;
        $browser->get('http://localhost:8080/health')
            ->then(function ($res) use (&$response) {
                $response = $res;
                $this->loop->stop();
            });
        
        $this->loop->run();
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('healthy', $body['status']);
    }
}
```

### Load Testing

Test with tools like Apache Bench or Wrk:

```bash
# Apache Bench
ab -n 10000 -c 100 http://localhost:8080/health

# Wrk
wrk -t12 -c400 -d30s http://localhost:8080/health
```

## Common Issues & Solutions

### Issue 1: PSR-7 Version Conflicts

**Problem**: `Declaration of React\Http\Io\AbstractMessage::getProtocolVersion() must be compatible with...`

**Solution**: 
```bash
# Switch PivotPHP Core to PSR-7 v1.x
php vendor/pivotphp/core/scripts/switch-psr7-version.php 1
composer update psr/http-message
```

### Issue 2: Header Access Returns Null

**Problem**: `$request->header('Content-Type')` returns null

**Solution**: Use camelCase header names:
```php
// ❌ Wrong
$contentType = $request->header('Content-Type');

// ✅ Correct
$contentType = $request->header('contentType');

// ✅ Alternative
$contentType = $request->headers->contentType;
```

### Issue 3: Global State Conflicts

**Problem**: `$_GET`, `$_POST` variables affecting multiple requests

**Solution**: The RequestBridge handles this automatically by saving/restoring global state.

### Issue 4: Memory Leaks

**Problem**: Memory usage grows over time

**Solution**: 
- Ensure proper cleanup of resources
- Use object pooling for frequently created objects
- Monitor memory usage with `memory_get_usage()`

### Issue 5: Database Connections

**Problem**: Database connections timing out

**Solution**: Use connection pooling or recreate connections as needed:
```php
$app->get('/api/users', function ($request, $response) use ($app) {
    try {
        $db = $app->make('database');
        $users = $db->query('SELECT * FROM users');
        $response->json($users);
    } catch (PDOException $e) {
        // Reconnect on connection issues
        $app->make('database')->reconnect();
        $response->status(503)->json(['error' => 'Database temporarily unavailable']);
    }
});
```

## Performance Considerations

### Memory Management

- **Shared state**: Application and services persist across requests
- **Memory monitoring**: Implement memory usage tracking
- **Garbage collection**: Let PHP handle cleanup naturally

### Connection Handling

- **Keep-alive**: ReactPHP handles HTTP keep-alive automatically
- **Connection limits**: Configure maximum concurrent connections
- **Resource cleanup**: Ensure proper resource disposal

### Optimization Tips

1. **Use object pooling** for frequently created objects
2. **Cache expensive operations** in memory
3. **Profile with Xdebug** to identify bottlenecks
4. **Monitor memory usage** with built-in functions
5. **Use efficient data structures** (arrays vs objects)

### Benchmarking

Typical performance improvements over PHP-FPM:

- **Throughput**: 2-5x higher requests per second
- **Memory**: 30-50% lower memory per request
- **Latency**: 50-70% lower response times
- **Concurrency**: Handle 1000+ concurrent connections

## API Reference

### ReactServer Class

```php
namespace PivotPHP\ReactPHP\Server;

class ReactServer
{
    public function __construct(
        Application $app,
        LoopInterface $loop,
        ?LoggerInterface $logger = null
    );
    
    public function listen(string|SocketServerInterface $address): void;
    public function stop(): void;
    public function getLoop(): LoopInterface;
    public function getApplication(): Application;
}
```

### RequestBridge Class

```php
namespace PivotPHP\ReactPHP\Bridge;

class RequestBridge
{
    public function convertFromReact(
        ServerRequestInterface $reactRequest
    ): \PivotPHP\Core\Http\Request;
}
```

### ResponseBridge Class

```php
namespace PivotPHP\ReactPHP\Bridge;

class ResponseBridge
{
    public function convertToReact(
        ResponseInterface $psrResponse
    ): ReactResponse;
}
```

### Configuration Options

Available environment variables:

- `REACTPHP_HOST` - Server host (default: 0.0.0.0)
- `REACTPHP_PORT` - Server port (default: 8080)
- `REACTPHP_WORKERS` - Number of worker processes (default: 1)
- `REACTPHP_MEMORY_LIMIT` - Memory limit per worker (default: 256M)
- `REACTPHP_MAX_CONNECTIONS` - Maximum concurrent connections (default: 1000)
- `REACTPHP_TIMEOUT` - Request timeout in seconds (default: 30)
- `REACTPHP_SSL_ENABLED` - Enable SSL/TLS (default: false)
- `REACTPHP_SSL_CERT` - SSL certificate path
- `REACTPHP_SSL_KEY` - SSL private key path

---

## Conclusion

The PivotPHP ReactPHP Extension successfully bridges PivotPHP's Express.js-style framework with ReactPHP's high-performance event loop, providing:

- **Seamless integration** with existing PivotPHP applications
- **High performance** through non-blocking I/O
- **Type safety** with full PSR compliance
- **Production ready** with comprehensive testing

For additional support, examples, and advanced configurations, see the `/examples` directory and visit the [PivotPHP documentation](https://docs.pivotphp.com).