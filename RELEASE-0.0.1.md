# PivotPHP ReactPHP v0.0.1 Release Notes

**Release Date:** December 2024  
**Version:** 0.0.1 (Initial Release)

## 🎉 Introduction

This is the initial release of PivotPHP ReactPHP - a high-performance continuous runtime extension for PivotPHP using ReactPHP's event-driven, non-blocking I/O model. This extension enables long-running PHP applications with persistent state and eliminates the bootstrap overhead of traditional PHP-FPM deployments.

## 🚀 Key Features

### Continuous Runtime
- **Persistent Application State**: Application remains in memory between requests
- **Elimination of Bootstrap Overhead**: No need to initialize the framework for each request
- **Connection Persistence**: Database connections and other resources stay alive
- **Shared Cache**: In-memory caching persists across requests

### Event-Driven Architecture
- **Non-Blocking I/O**: Handle multiple concurrent requests efficiently
- **ReactPHP Integration**: Built on ReactPHP's proven event loop
- **Async Support**: Native support for promises and async operations
- **High Concurrency**: Handle thousands of concurrent connections

### PSR-7 Compatibility
- **Bridge Pattern**: Seamless conversion between ReactPHP and PivotPHP PSR-7 implementations
- **Header Handling**: Proper conversion of HTTP headers to PivotPHP's camelCase format
- **Request/Response Conversion**: Complete request/response lifecycle support
- **Stream Support**: Efficient handling of request/response streams

## 📦 Installation

```bash
composer require pivotphp/reactphp:^0.0.1
```

### Requirements
- PHP 8.1 or higher
- PivotPHP Core ^1.0
- ReactPHP packages (automatically installed)

## 🏗️ Architecture

### Core Components

#### ReactServer (`src/Server/ReactServer.php`)
Main server class that bridges ReactPHP's HttpServer with PivotPHP Application:
- Event loop management
- Signal handling for graceful shutdown
- Request/response processing
- Error handling and logging

#### Bridge System (`src/Bridge/`)
- **RequestBridge**: Converts ReactPHP requests to PivotPHP requests using global state manipulation
- **ResponseBridge**: Converts PivotPHP responses to ReactPHP responses with streaming support

#### Service Provider (`src/Providers/ReactPHPServiceProvider.php`)
Registers all ReactPHP services with PivotPHP's dependency injection container:
- Event loop registration
- Bridge services
- Server configuration
- Console commands

#### Console Command (`src/Commands/ServeCommand.php`)
Provides CLI interface for server management:
- Server startup with configurable host/port
- Environment configuration
- Multi-worker support (experimental)

### PSR-7 Compatibility Layer
- **Psr7CompatibilityAdapter**: Handles version differences between ReactPHP's PSR-7 v1.x and PivotPHP's implementation
- **Global State Management**: Temporary manipulation of PHP globals for PivotPHP compatibility
- **Header Conversion**: Automatic conversion of HTTP headers to PivotPHP's camelCase format

## 🔧 Configuration

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
- `APP_DEBUG`: Enable debug mode for detailed error messages

## 🎯 Usage Examples

### Basic Server Setup
```php
<?php
use PivotPHP\Core\Application;
use PivotPHP\ReactPHP\Providers\ReactPHPServiceProvider;
use PivotPHP\ReactPHP\Server\ReactServer;

$app = new Application(__DIR__);
$app->register(new ReactPHPServiceProvider());

// Define routes
$router = $app->get('router');
$router->get('/', fn() => Response::json(['message' => 'Hello, ReactPHP!']));

// Start server
$server = $app->get(ReactServer::class);
$server->listen('0.0.0.0:8080');
```

### Console Command
```bash
# Start server with default settings
php artisan serve:reactphp

# Custom configuration
php artisan serve:reactphp --host=127.0.0.1 --port=8000 --env=development
```

### Async Operations
```php
use React\Promise\Promise;

$router->get('/async/fetch', function () use ($browser): Promise {
    return $browser->get('https://api.example.com/data')->then(
        fn($response) => Response::json(json_decode((string) $response->getBody()))
    );
});
```

## 🧪 Testing

### Test Suite
- **Bridge Tests**: Request/response conversion testing
- **Server Tests**: Server lifecycle and request handling
- **Integration Tests**: End-to-end functionality testing
- **PHPStan Level 9**: Strict static analysis compliance

### Quality Assurance
```bash
# Run all tests
composer test

# Run tests with coverage
composer test:coverage

# Quality checks (CS, PHPStan, Tests)
composer quality:check

# Code style checks
composer cs:check
composer cs:fix
```

## 📊 Performance

### Benefits
- **2-3x Higher Throughput**: Compared to traditional PHP-FPM
- **50% Lower Memory Per Request**: Shared application state
- **Faster Response Times**: No bootstrap overhead
- **Persistent Connections**: Database and cache connections stay alive

### Benchmarking
```bash
# ReactPHP server
ab -n 10000 -c 100 http://localhost:8080/

# Start development server for testing
composer server:start
```

## 🚨 Known Limitations

### Memory Management
- Long-running processes require careful memory management
- Memory leaks can accumulate over time
- Periodic monitoring recommended

### PHP Extension Compatibility
- Some PHP extensions may not be compatible with async operations
- Global state must be handled carefully
- File uploads are buffered in memory by default

### Development Considerations
- Debugging can be more complex than traditional PHP
- Application state persists between requests
- Error handling requires async-aware patterns

## 🔄 Production Deployment

### Process Management
Use a process manager like Supervisor:
```ini
[program:pivotphp-reactphp]
command=php /path/to/app/artisan serve:reactphp --port=8080
autostart=true
autorestart=true
user=www-data
numprocs=4
```

### Load Balancing
Use Nginx as a reverse proxy:
```nginx
upstream pivotphp_backend {
    server 127.0.0.1:8080;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    server 127.0.0.1:8083;
}

server {
    listen 80;
    location / {
        proxy_pass http://pivotphp_backend;
    }
}
```

## 🛣️ Future Roadmap

### Upcoming Features
- **WebSocket Support**: Real-time communication capabilities
- **HTTP/2 and HTTP/3**: Modern protocol support
- **Built-in Clustering**: Multi-core utilization
- **GraphQL Subscriptions**: Real-time GraphQL support
- **Server-Sent Events (SSE)**: Streaming updates
- **Enhanced Memory Management**: Automatic memory optimization

### Version 0.1.0 Goals
- Stability improvements
- Enhanced error handling
- Performance optimizations
- Extended middleware support
- Comprehensive documentation

## 🐛 Known Issues

1. **PSR-7 Version Compatibility**: Currently requires PivotPHP Core's PSR-7 version switching script
2. **Memory Accumulation**: Long-running processes may accumulate memory over time
3. **Global State Handling**: Some edge cases in global state management
4. **Multi-Worker Mode**: Experimental and not fully implemented

## 📚 Documentation

### Available Documentation
- `README.md`: Quick start guide and basic usage
- `CLAUDE.md`: Development guidance for AI assistants
- `docs/IMPLEMENTATION_GUIDE.md`: Detailed implementation guide
- `docs/TROUBLESHOOTING.md`: Common issues and solutions

### Examples
- `examples/server.php`: Basic server implementation
- `examples/async-example.php`: Async operations demonstration

## 🤝 Contributing

### Development Setup
```bash
git clone https://github.com/pivotphp/pivotphp-reactphp.git
cd pivotphp-reactphp
composer install
```

### Quality Standards
- PHP 8.1+ with strict typing
- PSR-12 coding standard
- PHPStan Level 9 compliance
- Comprehensive test coverage
- Documentation for all public APIs

### Testing
```bash
# Run all quality checks
composer quality:check

# Individual checks
composer phpstan
composer cs:check
composer test
```

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 📞 Support

- **GitHub Issues**: Report bugs and request features
- **Discussions**: https://github.com/PivotPHP/pivotphp-reactphp/discussions
- **Documentation**: Check the docs/ directory

## 🙏 Acknowledgments

- ReactPHP team for the excellent event-driven PHP foundation
- PivotPHP Core team for the flexible microframework architecture
- PHP community for continuous support and feedback

---

**Note**: This is an initial release intended for early adopters and testing. Production use should be carefully evaluated and monitored. Please report any issues or feedback to help improve future versions.