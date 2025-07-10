# Testing Guide

This guide covers the comprehensive test suite for the PivotPHP ReactPHP extension.

## Test Categories

### Unit Tests
Standard unit tests covering individual components:

```bash
# Run all unit tests
composer test

# Run with coverage report
composer test:coverage
```

### Performance Tests
Special test suites for performance, stress, and long-running scenarios:

```bash
# Run all performance tests
composer test:performance

# Run specific test groups
composer test:benchmark      # Benchmark tests
composer test:stress        # Stress tests
composer test:long-running  # Long-running stability tests
```

## Test Structure

### Unit Tests
Located in `tests/` directory:

- **Bridge Tests**: Request/Response conversion between ReactPHP and PivotPHP
- **Security Tests**: Request isolation, blocking code detection, memory management
- **Integration Tests**: Full server integration with PivotPHP application
- **Middleware Tests**: Security middleware functionality

### Performance Tests
Located in `tests/Performance/` directory:

#### Benchmark Tests (`BenchmarkTest.php`)
Measures baseline performance for different route types:
- Minimal route response time
- JSON response handling
- Middleware processing overhead
- Database query simulation
- Complex computation handling
- Concurrent request throughput

#### Stress Tests (`StressTest.php`)
Tests system behavior under high load:
- High concurrent request handling (100-1000 concurrent requests)
- Memory stability under load
- CPU-intensive request handling
- Large response streaming
- Error recovery and resilience

#### Long-Running Tests (`LongRunningTest.php`)
Validates stability over extended periods:
- Memory leak detection over time
- Global state isolation persistence
- Resource management (file handles, connections)
- Cache growth management
- Event loop stability

## Running Tests

### Quick Test Commands

```bash
# Quality check (code style, static analysis, unit tests)
composer quality:check

# Run specific test file
./vendor/bin/phpunit tests/Security/BlockingCodeDetectorTest.php

# Run tests with filter
./vendor/bin/phpunit --filter testDetectsSleepFunction
```

### Performance Test Execution

Performance tests are marked as skipped by default to prevent accidental execution. To run them:

```bash
# Remove skip annotations or run with --no-skip flag
./vendor/bin/phpunit -c phpunit-performance.xml --group=benchmark

# Run with custom memory limit
php -d memory_limit=1G vendor/bin/phpunit -c phpunit-performance.xml
```

### Continuous Integration

For CI environments, use only unit tests:

```yaml
# .github/workflows/tests.yml example
- name: Run tests
  run: composer test
```

## Writing Tests

### Unit Test Example

```php
public function testRequestBridgeConvertsHeaders(): void
{
    $reactRequest = new ServerRequest(
        'POST',
        new Uri('http://example.com/api'),
        ['Content-Type' => 'application/json']
    );
    
    $psrRequest = $this->bridge->convertFromReact($reactRequest);
    
    $this->assertEquals('application/json', $psrRequest->getHeaderLine('Content-Type'));
}
```

### Testing Callback Verification

```php
public function testCallbackInvocation(): void
{
    $expectedArgs = ['arg1', 'arg2'];
    $actualCallback = function ($arg1, $arg2) {
        return $arg1 . $arg2;
    };
    
    [$wrapper, $verifier] = AssertionHelper::createCallbackVerifier($this, $actualCallback, $expectedArgs);
    
    // Use the wrapper in your test
    $result = $wrapper('arg1', 'arg2');
    
    // Verify the callback was called with correct arguments
    $verifier();
    
    $this->assertEquals('arg1arg2', $result);
}
```

### Testing Requests Without Headers

```php
public function testMissingHostHeader(): void
{
    // Remove automatically added Host header
    $request = (new ServerRequest(
        'GET',
        new Uri('http://example.com/test'),
        []
    ))->withoutHeader('Host');
    
    $response = $this->middleware->process($request, $handler);
    
    // Assert specific status code, not ranges
    $this->assertEquals(400, $response->getStatusCode());
}
```

### Performance Test Example

```php
/**
 * @group stress
 */
public function testHighLoad(): void
{
    $this->markTestSkipped('Stress tests should be run manually');
    
    // Test implementation
}
```

### Best Practices

1. **Isolation**: Each test should be independent
2. **Cleanup**: Always clean up resources in `tearDown()`
3. **Assertions**: Use specific assertions for clarity
4. **Mocking**: Mock external dependencies
5. **Performance**: Skip heavy tests by default
6. **Output Buffer Management**: TestCase automatically handles output buffer isolation
7. **Callback Testing**: Use AssertionHelper::createCallbackVerifier() for proper callback verification
8. **Error Assertions**: Assert specific status codes rather than ranges for clear expectations

## Test Configuration

### PHPUnit Configuration

Two configuration files are provided:
- `phpunit.xml`: Standard unit tests
- `phpunit-performance.xml`: Performance test suite

### Environment Variables

Set these for testing:
```bash
export REACTPHP_TEST_HOST=127.0.0.1
export REACTPHP_TEST_PORT=18080
export REACTPHP_TEST_TIMEOUT=30
```

## Debugging Tests

### Enable Debug Output

```bash
# Run with verbose output
./vendor/bin/phpunit -v

# Show test execution flow
./vendor/bin/phpunit --debug
```

### Memory Profiling

```php
// Add to test
$this->memoryGuard->startMonitoring();
$this->memoryGuard->onMemoryLeak(function ($data) {
    var_dump($data);
});
```

### Performance Metrics

Performance tests output detailed metrics after completion:

```
Benchmark Results:
==================

minimal_route:
  Iterations: 1000
  Avg Time: 0.8421 ms
  P95: 1.2000 ms
  Throughput: 1187.65 req/s
```

## Common Issues

### Memory Limit Errors
```bash
# Increase memory limit for tests
php -d memory_limit=512M vendor/bin/phpunit
```

### Event Loop Issues
```php
// Always stop the loop in tearDown
protected function tearDown(): void
{
    Loop::get()->stop();
    parent::tearDown();
}
```

### Port Already in Use
```bash
# Use different port for tests
export REACTPHP_TEST_PORT=18081
```

## Contributing Tests

When adding new features:
1. Write unit tests first (TDD approach)
2. Add integration tests for server interaction
3. Consider performance implications
4. Document any special test requirements

Example PR checklist:
- [ ] Unit tests added/updated
- [ ] Integration tests if applicable
- [ ] Performance tests for critical paths
- [ ] All tests passing locally
- [ ] Documentation updated