# PivotPHP ReactPHP Troubleshooting Guide

## Common Issues and Solutions

Based on real development experience and issues encountered during implementation.

---

## PSR-7 Compatibility Issues

### Error: "Declaration of React\Http\Io\AbstractMessage::getProtocolVersion() must be compatible with..."

**Symptoms:**
```
Fatal error: Declaration of React\Http\Io\AbstractMessage::getProtocolVersion() 
must be compatible with Psr\Http\Message\MessageInterface::getProtocolVersion(): string
```

**Cause:** PivotPHP Core is using PSR-7 v2.x while ReactPHP requires PSR-7 v1.x.

**Solution:**
```bash
# Switch PivotPHP Core to PSR-7 v1.x
php vendor/pivotphp/core/scripts/switch-psr7-version.php 1
composer update psr/http-message

# Verify the change
php vendor/pivotphp/core/scripts/switch-psr7-version.php --check
```

**Prevention:** Always use PSR-7 v1.x when integrating with ReactPHP.

---

## Request/Response Handling Issues

### Error: "Argument #1 ($request) must be of type ?PivotPHP\Core\Http\Request, PivotPHP\Core\Http\Psr7\ServerRequest given"

**Symptoms:**
```
PivotPHP\Core\Core\Application::handle(): Argument #1 ($request) must be of type 
?PivotPHP\Core\Http\Request, PivotPHP\Core\Http\Psr7\ServerRequest given
```

**Cause:** RequestBridge is returning PSR-7 ServerRequest instead of PivotPHP's native Request class.

**Solution:** Ensure RequestBridge converts to PivotPHP Request:
```php
// In RequestBridge.php
public function convertFromReact(ServerRequestInterface $reactRequest): \PivotPHP\Core\Http\Request
{
    // Convert to PivotPHP Request, not PSR-7 ServerRequest
    return new \PivotPHP\Core\Http\Request($method, $path, $path);
}
```

**Prevention:** Always check return types match expected interfaces.

---

## Header Access Issues

### Error: Headers Return Null

**Symptoms:**
```php
$contentType = $request->header('Content-Type'); // Returns null
$auth = $request->header('Authorization');       // Returns null
```

**Cause:** PivotPHP converts header names to camelCase during Request construction.

**Solution:** Use camelCase header names:
```php
// ❌ Wrong - returns null
$contentType = $request->header('Content-Type');
$auth = $request->header('Authorization');
$apiKey = $request->header('X-API-Key');

// ✅ Correct - returns actual values
$contentType = $request->header('contentType');
$auth = $request->header('authorization');
$apiKey = $request->header('xApiKey');

// ✅ Alternative methods
$contentType = $request->headers->contentType;
$contentType = $request->headers->contentType();
```

**Header Conversion Reference:**
- `Content-Type` → `contentType`
- `Authorization` → `authorization`
- `X-API-Key` → `xApiKey`
- `Accept-Language` → `acceptLanguage`
- `User-Agent` → `userAgent`
- `X-Forwarded-For` → `xForwardedFor`

**Prevention:** Always use camelCase when accessing headers programmatically.

---

## Express.js Pattern Issues

### Error: Controllers Trying to Return Response

**Symptoms:**
```php
// This pattern doesn't work with PivotPHP
public function index(Request $request): Response
{
    return Response::json($data);
}
```

**Cause:** PivotPHP uses Express.js style where response is passed as parameter.

**Solution:** Use Express.js pattern:
```php
// ✅ Correct Express.js style
public function index(Request $request, Response $response): void
{
    $response->json($data);
}

// ✅ Route definition
$router->get('/users', function ($request, $response) {
    $response->json(['users' => []]);
});
```

**Prevention:** Always use `(request, response)` parameters and return `void`.

---

## Service Provider and Container Issues

### Error: "Too few arguments to function Application::get()"

**Symptoms:**
```
ArgumentCountError: Too few arguments to function PivotPHP\Core\Core\Application::get(), 
1 passed and exactly 2 expected
```

**Cause:** Using wrong method to access services from container.

**Solution:** Use `make()` instead of `get()`:
```php
// ❌ Wrong
$router = $app->get('router');

// ✅ Correct
$router = $app->make('router');

// ✅ With existence check
if ($app->has('router')) {
    $router = $app->make('router');
}
```

**Prevention:** Use PSR-11 container methods: `has()`, `make()`.

---

### Error: Services Not Available

**Symptoms:** Services registered but not accessible, "Service not found" errors.

**Cause:** Accessing services before calling `boot()`.

**Solution:** Always boot before accessing services:
```php
// ✅ Correct order
$app->register(new AppServiceProvider());
$app->register(new RouteServiceProvider());
$app->boot(); // Boot first!

// Now services are available
$config = $app->make('config');
$router = $app->make('router');
```

**Prevention:** Follow the register → boot → use pattern.

---

## Testing Issues

### Error: "Class 'PivotPHP\Core\Http\Factory\Psr17Factory' not found"

**Symptoms:**
```
Error: Class "PivotPHP\Core\Http\Factory\Psr17Factory" not found
```

**Cause:** Using incorrect factory class names.

**Solution:** Use correct PivotPHP factory classes:
```php
// ❌ Wrong - this class doesn't exist
use PivotPHP\Core\Http\Factory\Psr17Factory;

// ✅ Correct factory classes
use PivotPHP\Core\Http\Psr7\Factory\RequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\ResponseFactory;
use PivotPHP\Core\Http\Psr7\Factory\ServerRequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\StreamFactory;
use PivotPHP\Core\Http\Psr7\Factory\UriFactory;
```

**Prevention:** Check actual class locations in PivotPHP Core.

---

### Error: "Call to undefined method React\EventLoop\Loop::create()"

**Symptoms:**
```
Error: Call to undefined method React\EventLoop\Loop::create()
```

**Cause:** Using non-existent ReactPHP Loop method.

**Solution:** Use existing loop instance:
```php
// ❌ Wrong - create() doesn't exist
protected function tearDown(): void
{
    $this->loop->stop();
    Loop::set(Loop::create()); // This fails
}

// ✅ Correct - just stop the loop
protected function tearDown(): void
{
    $this->loop->stop();
    parent::tearDown();
}
```

**Prevention:** Use ReactPHP documentation for correct Loop API.

---

## Request Data Access Issues

### Error: "Call to undefined method HeaderRequest::set()"

**Symptoms:**
```
Error: Call to undefined method PivotPHP\Core\Http\HeaderRequest::set()
```

**Cause:** Trying to modify immutable Request objects.

**Solution:** PivotPHP Request is immutable by design. Use global variables:
```php
// ❌ Wrong - trying to modify after creation
$pivotRequest->headers->set('content-type', 'application/json');
$pivotRequest->query->page = 2;

// ✅ Correct - set globals before creation
$_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
$_GET['page'] = '2';
$_POST['data'] = 'value';

$pivotRequest = new Request('POST', '/users', '/users');
```

**Prevention:** Understand PivotPHP's immutable design pattern.

---

## ReactPHP Server Issues

### Error: "Address already in use (EADDRINUSE)"

**Symptoms:**
```
RuntimeException: Failed to listen on "tcp://127.0.0.1:8080": Address already in use (EADDRINUSE)
```

**Cause:** Port is already in use by another process.

**Solutions:**
```bash
# Find process using the port
lsof -i :8080
netstat -tulpn | grep :8080

# Kill the process
kill -9 <PID>

# Or use a different port
$server->listen('0.0.0.0:8081');
```

**Prevention:** Use different ports for different environments.

---

### Error: "Typed property ReactServer::$socketServer must not be accessed before initialization"

**Symptoms:**
```
Error: Typed property PivotPHP\ReactPHP\Server\ReactServer::$socketServer 
must not be accessed before initialization
```

**Cause:** Accessing ReactServer properties before calling `listen()`.

**Solution:** Call methods in correct order:
```php
$server = new ReactServer($app, $loop);
$server->listen('0.0.0.0:8080'); // Initialize first
$server->getLoop(); // Now safe to access properties
```

**Prevention:** Always call `listen()` before accessing server properties.

---

## Memory and Performance Issues

### Issue: Memory Leaks in Long-Running Processes

**Symptoms:** Memory usage grows continuously over time.

**Debugging:**
```php
// Add memory monitoring
$app->get('/memory', function ($request, $response) {
    $response->json([
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'memory_limit' => ini_get('memory_limit')
    ]);
});
```

**Solutions:**
1. **Avoid static arrays** that grow over time
2. **Clean up resources** explicitly
3. **Use object pooling** for frequently created objects
4. **Monitor memory** regularly

```php
// ❌ Wrong - static array grows forever
class MyController {
    private static $cache = [];
    
    public function index($request, $response) {
        self::$cache[] = $someData; // Memory leak!
    }
}

// ✅ Correct - limit cache size
class MyController {
    private static $cache = [];
    private const MAX_CACHE_SIZE = 1000;
    
    public function index($request, $response) {
        if (count(self::$cache) >= self::MAX_CACHE_SIZE) {
            array_shift(self::$cache); // Remove oldest
        }
        self::$cache[] = $someData;
    }
}
```

**Prevention:** Design for long-running processes from the start.

---

## Database Connection Issues

### Issue: Database Connections Timing Out

**Symptoms:** "MySQL server has gone away" errors after period of inactivity.

**Solution:** Implement connection health checks:
```php
$app->singleton('database', function() {
    return new DatabaseConnection();
});

$app->get('/api/users', function ($request, $response) use ($app) {
    try {
        $db = $app->make('database');
        
        // Health check before query
        if (!$db->ping()) {
            $db->reconnect();
        }
        
        $users = $db->query('SELECT * FROM users');
        $response->json($users);
        
    } catch (PDOException $e) {
        $response->status(503)->json(['error' => 'Database unavailable']);
    }
});
```

**Prevention:** Use connection pooling or implement automatic reconnection.

---

## Development and Debugging

### Enable Debug Mode

**Set environment variables:**
```env
APP_DEBUG=true
APP_ENV=development
REACTPHP_DEBUG=true
```

**Add debug middleware:**
```php
if ($_ENV['APP_DEBUG'] ?? false) {
    $app->use(new DebugMiddleware());
}
```

### Logging Configuration

**Add comprehensive logging:**
```php
use Psr\Log\LoggerInterface;

$server = new ReactServer($app, $loop, $logger);

// Log all requests
$app->use(function ($request, $response, $next) use ($logger) {
    $start = microtime(true);
    
    $next($request, $response);
    
    $duration = microtime(true) - $start;
    $logger->info('Request processed', [
        'method' => $request->getMethod(),
        'path' => $request->getPath(),
        'duration' => $duration,
        'memory' => memory_get_usage(true)
    ]);
});
```

---

## Quality Assurance

### Run All Quality Checks

```bash
# PHPStan static analysis
composer phpstan

# PSR-12 code style check
composer cs:check

# Fix code style issues
composer cs:fix

# Run all tests
composer test

# Run all quality checks
composer quality:check
```

### Continuous Integration

**Example GitHub Actions workflow:**
```yaml
name: CI

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          
      - run: composer install
      - run: composer quality:check
      - run: composer test
```

---

## Getting Help

### Debug Information to Collect

When reporting issues, include:

1. **PHP Version:** `php --version`
2. **PivotPHP Version:** Check `composer.json`
3. **ReactPHP Version:** Check `composer.json`
4. **PSR-7 Version:** `php vendor/pivotphp/core/scripts/switch-psr7-version.php --check`
5. **Error Messages:** Full stack traces
6. **Configuration:** Server settings, environment variables
7. **Test Case:** Minimal code to reproduce the issue

### Useful Commands

```bash
# Check PSR-7 version
php vendor/pivotphp/core/scripts/switch-psr7-version.php --check

# Validate composer dependencies
composer validate

# Show installed packages
composer show

# Check for security vulnerabilities
composer audit

# Run ReactPHP server with debugging
APP_DEBUG=true php server.php
```

---

This troubleshooting guide covers the most common issues encountered during ReactPHP integration with PivotPHP Core. Most problems stem from PSR-7 version conflicts, misunderstanding PivotPHP's immutable design, or incorrect usage of the Express.js pattern.