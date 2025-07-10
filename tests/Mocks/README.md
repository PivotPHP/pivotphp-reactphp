# MockBrowser Testing Guide

The `MockBrowser` class provides a comprehensive mock implementation of the ReactPHP `Browser` class for testing purposes. This allows you to test HTTP client functionality without making actual network requests.

## Features

- **Complete HTTP Methods**: Supports GET, POST, PUT, DELETE, PATCH, HEAD, and generic request methods
- **Predefined Responses**: Set specific responses for different URLs
- **Error Simulation**: Simulate network errors and HTTP error responses
- **Request Recording**: Track all requests made for verification
- **Configuration Options**: Mock all Browser configuration methods
- **Promise-Based**: Returns proper ReactPHP promises like the real Browser

## Basic Usage

```php
use PivotPHP\ReactPHP\Tests\Mocks\MockBrowser;

// Create mock browser
$mockBrowser = new MockBrowser();

// Set predefined response
$response = MockBrowser::createJsonResponse(['message' => 'Hello World']);
$mockBrowser->setResponse('https://api.example.com/hello', $response);

// Make request
$promise = $mockBrowser->get('https://api.example.com/hello');

// Handle response
$promise->then(function ($response) {
    $data = json_decode((string) $response->getBody(), true);
    echo $data['message']; // "Hello World"
});
```

## Setting Up Responses

### JSON Responses
```php
$response = MockBrowser::createJsonResponse([
    'name' => 'John Doe',
    'email' => 'john@example.com'
], 200);
$mockBrowser->setResponse('https://api.example.com/user/123', $response);
```

### Error Responses
```php
$errorResponse = MockBrowser::createErrorResponse('Not found', 404);
$mockBrowser->setResponse('https://api.example.com/nonexistent', $errorResponse);
```

### Multiple Responses
```php
$responses = [
    'https://api.github.com' => MockBrowser::createJsonResponse(['service' => 'github']),
    'https://api.example.com' => MockBrowser::createJsonResponse(['service' => 'example']),
];
$mockBrowser->setResponses($responses);
```

### Exceptions
```php
$exception = new \RuntimeException('Network timeout');
$mockBrowser->setError('https://api.example.com/timeout', $exception);
```

## HTTP Methods

All standard HTTP methods are supported:

```php
// GET request
$mockBrowser->get('https://api.example.com/users');

// POST request with data
$mockBrowser->post(
    'https://api.example.com/users',
    ['Content-Type' => 'application/json'],
    json_encode(['name' => 'John'])
);

// PUT request
$mockBrowser->put('https://api.example.com/users/123', [], $data);

// DELETE request
$mockBrowser->delete('https://api.example.com/users/123');

// PATCH request
$mockBrowser->patch('https://api.example.com/users/123', [], $data);

// HEAD request
$mockBrowser->head('https://api.example.com/users/123');

// Generic request
$mockBrowser->request('OPTIONS', 'https://api.example.com/users');
```

## Request Verification

### Getting All Requests
```php
$requests = $mockBrowser->getRequests();
foreach ($requests as $request) {
    echo $request['method'] . ' ' . $request['url'] . "\n";
    echo 'Headers: ' . json_encode($request['headers']) . "\n";
    echo 'Body: ' . $request['body'] . "\n";
}
```

### Getting Last Request
```php
$lastRequest = $mockBrowser->getLastRequest();
if ($lastRequest) {
    assert($lastRequest['method'] === 'POST');
    assert($lastRequest['url'] === 'https://api.example.com/users');
}
```

### Clearing Requests
```php
$mockBrowser->clearRequests();
```

## Configuration

MockBrowser supports all Browser configuration methods:

```php
$configuredBrowser = $mockBrowser
    ->withTimeout(60.0)
    ->withFollowRedirects(false)
    ->withHeader('User-Agent', 'MyApp/1.0')
    ->withRejectErrorResponse(false);

// Get configuration for testing
$config = $configuredBrowser->getConfiguration();
```

## Parallel Requests

Test parallel requests using Promise::all():

```php
$promises = [
    'users' => $mockBrowser->get('https://api.example.com/users'),
    'posts' => $mockBrowser->get('https://api.example.com/posts'),
];

\React\Promise\all($promises)->then(function ($responses) {
    // All requests completed
    $usersResponse = $responses['users'];
    $postsResponse = $responses['posts'];
});
```

## Integration with ResponseHelper

Use with the `ResponseHelper` class for easier testing:

```php
use PivotPHP\ReactPHP\Tests\Helpers\ResponseHelper;

// Create mock browser with predefined responses
$responses = [
    'https://api.github.com' => MockBrowser::createJsonResponse(['service' => 'github']),
];
$mockBrowser = ResponseHelper::createMockBrowser($responses);

// Use in tests
$promise = $mockBrowser->get('https://api.github.com');
$data = ResponseHelper::getJsonBody($promise);
```

## Testing Async Examples

For testing the async examples in your codebase:

```php
public function testAsyncFetchRoute(): void
{
    $mockBrowser = new MockBrowser();
    
    // Mock GitHub API response
    $githubResponse = MockBrowser::createJsonResponse([
        'name' => 'pivotphp-core',
        'description' => 'A lightweight PHP microframework',
        'stargazers_count' => 100,
        'language' => 'PHP',
    ]);
    
    $mockBrowser->setResponse('https://api.github.com/repos/pivotphp/core', $githubResponse);
    
    // Replace the real browser with mock in your application
    // ... test the route that uses the browser
    
    // Verify the request was made
    $requests = $mockBrowser->getRequests();
    self::assertCount(1, $requests);
    self::assertEquals('GET', $requests[0]['method']);
    self::assertEquals('https://api.github.com/repos/pivotphp/core', $requests[0]['url']);
}
```

## Best Practices

1. **Always verify requests**: Check that expected requests were made with correct parameters
2. **Use specific URLs**: Set responses for exact URLs your code will request
3. **Test error scenarios**: Include tests for network errors and HTTP error responses
4. **Clear requests between tests**: Use `clearRequests()` to avoid test interference
5. **Use helper methods**: Leverage `createJsonResponse()` and `createErrorResponse()` for consistency

## Real-World Example

Here's a complete example testing an async route:

```php
public function testAsyncRoute(): void
{
    $mockBrowser = new MockBrowser();
    
    // Set up mock responses
    $mockBrowser->setResponse('https://api.github.com/repos/pivotphp/core', 
        MockBrowser::createJsonResponse([
            'name' => 'pivotphp-core',
            'description' => 'A lightweight PHP microframework',
            'stargazers_count' => 100,
            'language' => 'PHP',
        ])
    );
    
    // Test the route handler
    $router = $this->app->make(Router::class);
    $router->get('/async/fetch', function () use ($mockBrowser): Promise {
        return new Promise(function ($resolve) use ($mockBrowser) {
            $mockBrowser->get('https://api.github.com/repos/pivotphp/core')->then(
                function ($response) use ($resolve) {
                    $data = json_decode((string) $response->getBody(), true);
                    
                    $resolve(Response::json([
                        'repository' => $data['name'] ?? 'unknown',
                        'description' => $data['description'] ?? '',
                        'stars' => $data['stargazers_count'] ?? 0,
                        'language' => $data['language'] ?? 'PHP',
                    ]));
                },
                function ($error) use ($resolve) {
                    $resolve(Response::json([
                        'error' => 'Failed to fetch repository data',
                        'message' => $error->getMessage(),
                    ], 500));
                }
            );
        });
    });
    
    // Make request to the route
    $request = new ServerRequest('GET', new Uri('http://localhost/async/fetch'));
    $promise = $this->server->handleRequest($request);
    
    // Verify response
    $response = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
    });
    
    Loop::get()->futureTick(function () {
        Loop::get()->stop();
    });
    Loop::get()->run();
    
    // Assertions
    self::assertNotNull($response);
    self::assertEquals(200, $response->getStatusCode());
    
    $body = json_decode((string) $response->getBody(), true);
    self::assertEquals('pivotphp-core', $body['repository']);
    self::assertEquals(100, $body['stars']);
    
    // Verify the HTTP request was made
    $requests = $mockBrowser->getRequests();
    self::assertCount(1, $requests);
    self::assertEquals('GET', $requests[0]['method']);
    self::assertEquals('https://api.github.com/repos/pivotphp/core', $requests[0]['url']);
}
```

This MockBrowser implementation provides everything you need to thoroughly test ReactPHP HTTP client functionality without external dependencies.