# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.2] - 2025-01-09

### Added
- Full compatibility with PivotPHP Core 1.1.0
- Support for high-performance mode features from PivotPHP 1.1.0
- Advanced features example (`examples/advanced-features.php`) demonstrating:
  - Server-Sent Events (SSE) streaming
  - File streaming with chunked transfer
  - Long polling for real-time updates
  - Async batch processing
  - Hooks system integration
- Streaming response detection based on headers and content type
- Improved error handling with support for custom error handlers
- Middleware aliases support for ReactPHP-specific middleware
- Better integration with PivotPHP's container system

### Changed
- Updated `RequestBridge` to use native PSR-7 support from PivotPHP Core 1.1.0
- Updated `ResponseBridge` to work directly with PSR-7 responses without compatibility layer
- Improved `ReactServer` with better Application integration and streaming support
- Updated `ReactPHPServiceProvider` to use new PivotPHP Core 1.1.0 APIs
- Updated all examples to use new Application namespace (`PivotPHP\Core\Core\Application`)
- Changed service provider registration to use class name instead of instance
- Updated container access methods to use `getContainer()`, `getConfig()`, and `make()`

### Removed
- Removed obsolete `Psr7CompatibilityAdapter` (no longer needed with PivotPHP Core 1.1.0's native PSR-7 support)

### Fixed
- Fixed namespace issues with PivotPHP Core classes
- Fixed ServiceProvider constructor requirements
- Fixed middleware registration to use `$app->use()` method
- Resolved all code style issues for PSR-12 compliance

### Dependencies
- Updated minimum PivotPHP Core requirement to 1.1.0

## [0.0.1] - 2025-01-09

### Added
- Initial release of PivotPHP ReactPHP Extension
- ReactPHP server integration with PivotPHP framework
- PSR-7 request/response bridge between ReactPHP and PivotPHP
- Service provider for easy integration
- Console command `serve:reactphp` for starting the server
- Support for async operations and promises
- Configuration file support
- Basic examples (server.php and async-example.php)
- Full test coverage for core components
- PHPStan Level 9 static analysis
- PSR-12 code style compliance

### Features
- Continuous runtime to keep application in memory between requests
- Event-driven architecture with non-blocking I/O
- High performance by eliminating bootstrap overhead
- Full PSR-7 compatibility with PivotPHP's implementation
- Middleware pipeline support
- Graceful server shutdown with signal handling (SIGTERM, SIGINT)

### Dependencies
- Requires PHP 8.1 or higher
- Compatible with PivotPHP Core 1.0+
- ReactPHP HTTP Server 1.9+
- PSR-7 Message Interface 1.x (ReactPHP requirement)

[0.0.1]: https://github.com/PivotPHP/pivotphp-reactphp/releases/tag/v0.0.1