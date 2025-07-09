# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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