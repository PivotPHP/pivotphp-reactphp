# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

This is the PivotPHP ReactPHP extension - a high-performance continuous runtime extension for PivotPHP using ReactPHP's event-driven, non-blocking I/O model. This extension bridges PivotPHP's PSR-7 implementation with ReactPHP's server to provide persistent application state and eliminate bootstrap overhead.

## Essential Commands

### Development
```bash
# Quality checks
composer quality:check    # Run all quality checks (CS, PHPStan, tests)
composer phpstan         # Static analysis (Level 9 strict)
composer cs:check        # PSR-12 code style check
composer cs:fix          # Auto-fix code style issues

# Testing
composer test            # Run all tests
composer test:coverage   # Run tests with HTML coverage report

# Server operations
composer server:start    # Start ReactPHP server (examples/server.php)
```

### Server Management
```bash
# Start development server
php examples/server.php

# Start async features example
php examples/async-example.php

# Console command (if integrated with PivotPHP console)
php artisan serve:reactphp --host=0.0.0.0 --port=8080
```

### PSR-7 Version Management
```bash
# Check current PSR-7 version in PivotPHP Core
php vendor/pivotphp/core/scripts/switch-psr7-version.php --check

# Switch to PSR-7 v1.x for ReactPHP compatibility (if needed)
php vendor/pivotphp/core/scripts/switch-psr7-version.php 1
composer update psr/http-message

# Note: Upcoming PivotPHP Core version will have native PSR-7 support
```

## Code Architecture

### Core Components
1. **ReactServer** (`src/Server/ReactServer.php`): Main server class that bridges ReactPHP's HttpServer with PivotPHP Application
2. **Request/Response Bridges** (`src/Bridge/`): Convert between ReactPHP and PivotPHP PSR-7 implementations
3. **Service Provider** (`src/Providers/ReactPHPServiceProvider.php`): Registers all ReactPHP services with PivotPHP's container
4. **Console Command** (`src/Commands/ServeCommand.php`): Provides CLI interface for server management

### Request Flow Architecture
```
ReactPHP Request → RequestBridge → PivotPHP Request → Application → PivotPHP Response → ResponseBridge → ReactPHP Response
```

### PSR-7 Compatibility Layer
The extension includes a PSR-7 compatibility adapter (`src/Adapter/Psr7CompatibilityAdapter.php`) that handles version differences between ReactPHP's PSR-7 v1.x requirements and PivotPHP's PSR-7 implementation. Note: Future PivotPHP Core versions will provide native PSR-7 support, reducing the need for this compatibility layer.

### Bridge Pattern Implementation
- **RequestBridge**: Temporarily manipulates global state ($_SERVER, $_GET, $_POST) to create PivotPHP Request objects, then restores original state
- **ResponseBridge**: Converts PivotPHP Response objects to ReactPHP Response objects, handling streaming and content types

### Service Provider Pattern
ReactPHP services are registered via `ReactPHPServiceProvider` which:
- Registers ReactPHP event loop as singleton
- Binds request/response bridges
- Configures ReactServer with application instance
- Registers console commands if available

## Key Implementation Details

### Global State Management
The RequestBridge uses a critical pattern for PivotPHP compatibility:
```php
// Save original state
$originalServer = $_SERVER ?? [];
$originalGet = $_GET ?? [];
$originalPost = $_POST ?? [];

try {
    // Modify globals for PivotPHP Request construction
    $_SERVER = $this->prepareServerVariables($reactRequest);
    $_GET = $reactRequest->getQueryParams();
    $_POST = $this->preparePostData($reactRequest);
    
    // Create PivotPHP Request (reads from globals)
    $pivotRequest = new Request($method, $path, $path);
    
    return $pivotRequest;
} finally {
    // Always restore original state
    $_SERVER = $originalServer;
    $_GET = $originalGet;
    $_POST = $originalPost;
}
```

### Header Name Conversion
PivotPHP converts HTTP headers to camelCase format:
- `Content-Type` → `contentType`
- `Authorization` → `authorization`
- `X-API-Key` → `xApiKey`
- `Accept-Language` → `acceptLanguage`

### Event Loop Management
The server uses ReactPHP's event loop with proper signal handling for graceful shutdown (SIGTERM, SIGINT).

### Memory Management
Continuous runtime means:
- Application and services persist across requests
- Database connections can be kept alive
- Cached data survives between requests
- Memory usage must be monitored

## Testing Approach

### Test Structure
- Tests extend custom `TestCase` which sets up ReactPHP event loop and PSR-7 factories
- Each test creates fresh application instance to avoid state pollution
- Request/Response bridges are tested with various HTTP scenarios

### Testing PSR-7 Factories
The codebase uses specific PivotPHP PSR-7 factories:
```php
use PivotPHP\Core\Http\Psr7\Factory\RequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\ResponseFactory;
use PivotPHP\Core\Http\Psr7\Factory\ServerRequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\StreamFactory;
use PivotPHP\Core\Http\Psr7\Factory\UriFactory;
```

### Test Coverage
- Request bridge conversion (headers, body, query params)
- Response bridge conversion (status codes, headers, streaming)
- Server lifecycle (start, stop, request handling)
- Error handling and recovery

## Configuration

### Default Configuration
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

### Environment Variables
- `REACTPHP_HOST`: Server host (default: 0.0.0.0)
- `REACTPHP_PORT`: Server port (default: 8080)
- `REACTPHP_STREAMING`: Enable streaming requests
- `REACTPHP_MAX_CONCURRENT_REQUESTS`: Concurrent request limit
- `APP_DEBUG`: Enable debug mode for error details

## Performance Considerations

### Continuous Runtime Benefits
- No bootstrap overhead per request
- Persistent database connections
- Shared application state and caches
- Faster response times under load

### Production Deployment
- Use process managers (Supervisor) for reliability
- Load balance across multiple ports
- Monitor memory usage over time
- Configure appropriate request limits

## Development Workflow

1. **Quality Checks**: Always run `composer quality:check` before committing
2. **PHPStan Level 9**: Code must pass strict static analysis
3. **PSR-12 Standard**: Code style must be compliant
4. **Test Coverage**: All new features require tests
5. **Documentation**: Update IMPLEMENTATION_GUIDE.md for significant changes

## Common Issues and Solutions

### PSR-7 Version Conflicts
**Problem**: ReactPHP requires PSR-7 v1.x but PivotPHP uses v2.x
**Solution**: Use PivotPHP's built-in version switching script (temporary solution until native PSR-7 support is available)

### Request Object Immutability
**Problem**: PivotPHP Request objects are immutable
**Solution**: Use global state manipulation pattern in RequestBridge

### Memory Leaks in Long-Running Processes
**Problem**: Continuous runtime can accumulate memory
**Solution**: Monitor usage, use weak references, periodic cleanup

## Future Enhancements

- WebSocket support for real-time communication
- HTTP/2 and HTTP/3 support
- Built-in clustering for multi-core utilization
- Server-sent events (SSE) implementation
- Enhanced middleware pipeline integration