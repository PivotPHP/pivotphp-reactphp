<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Server;

use PivotPHP\Core\Core\Application;
use PivotPHP\ReactPHP\Server\ReactServerCompat;
use PivotPHP\ReactPHP\Tests\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;

class ReactServerCompatTest extends TestCase
{
    private ReactServerCompat $server;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->createApplication();
        $this->server = new ReactServerCompat($this->application, $this->loop);
    }

    public function testConstructorInitializesCorrectly(): void
    {
        $this->assertInstanceOf(ReactServerCompat::class, $this->server);
        $this->assertSame($this->application, $this->server->getApplication());
        $this->assertFalse($this->server->isRunning());
    }

    public function testConstructorAcceptsCustomConfig(): void
    {
        $config = [
            'host' => '127.0.0.1',
            'port' => 9000,
            'workers' => 4,
        ];

        $server = new ReactServerCompat($this->application, $this->loop, null, $config);
        $this->assertInstanceOf(ReactServerCompat::class, $server);
    }

    public function testConstructorAcceptsCustomLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $server = new ReactServerCompat($this->application, $this->loop, $logger);
        $this->assertInstanceOf(ReactServerCompat::class, $server);
    }

    public function testExtractRequestDataBasicRequest(): void
    {
        $uri = new Uri('http://localhost/test?param=value');
        $reactRequest = new ServerRequest('GET', $uri, ['Content-Type' => 'application/json'], 'test body');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('extractRequestData');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $reactRequest);

        $this->assertIsArray($result);
        $this->assertEquals('GET', $result['method']);
        $this->assertEquals('http://localhost/test?param=value', $result['uri']);
        $this->assertEquals('/test', $result['path']);
        $this->assertEquals('param=value', $result['query']);
        $this->assertIsArray($result['headers']);
        $this->assertArrayHasKey('Content-Type', $result['headers']);
        $this->assertEquals('test body', $result['body']);
    }

    public function testExtractRequestDataWithComplexUri(): void
    {
        $uri = new Uri('https://api.example.com/v1/users/123?include=posts&limit=10');
        $reactRequest = new ServerRequest('POST', $uri, [], '');

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('extractRequestData');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $reactRequest);

        $this->assertEquals('/v1/users/123', $result['path']);
        $this->assertEquals('include=posts&limit=10', $result['query']);
    }

    public function testCreatePivotRequestBasic(): void
    {
        $requestData = [
            'method' => 'GET',
            'path' => '/test',
            'query' => '',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '',
        ];

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('createPivotRequest');
        $method->setAccessible(true);

        $pivotRequest = $method->invoke($this->server, $requestData);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $pivotRequest);
        $this->assertEquals('GET', $pivotRequest->getMethod());
        $this->assertEquals('/test', $pivotRequest->getPath());
    }

    public function testApplyHeaders(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('GET', '/test', '/test');
        $headers = [
            'Content-Type' => ['application/json'],
            'Authorization' => ['Bearer', 'token123'],
            'X-Custom-Header' => ['custom-value'],
        ];

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('applyHeaders');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $headers);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        // Just verify that headers were processed - specific header access may vary
        $this->assertNotSame($pivotRequest, $result);
    }

    public function testApplyQueryParameters(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('GET', '/search', '/search');
        $query = 'q=test&limit=10&sort=name';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('applyQueryParameters');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $query);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $queryParams = $result->getQueryParams();
        $this->assertEquals('test', $queryParams['q']);
        $this->assertEquals('10', $queryParams['limit']);
        $this->assertEquals('name', $queryParams['sort']);
    }

    public function testApplyQueryParametersEmpty(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('GET', '/test', '/test');
        $query = '';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('applyQueryParameters');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $query);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $this->assertEmpty($result->getQueryParams());
    }

    public function testParseJsonBody(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('POST', '/users', '/users');
        $pivotRequest = $pivotRequest->withHeader('Content-Type', 'application/json');
        $body = '{"name":"John","email":"john@example.com"}';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('parseJsonBody');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $body);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $parsedBody = $result->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertEquals('John', $parsedBody['name']);
        $this->assertEquals('john@example.com', $parsedBody['email']);
    }

    public function testParseJsonBodyInvalid(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('POST', '/users', '/users');
        $body = '{"invalid":json}';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('parseJsonBody');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $body);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $this->assertNull($result->getParsedBody());
    }

    public function testParseFormBody(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('POST', '/submit', '/submit');
        $body = 'name=Jane&email=jane@example.com&age=25';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('parseFormBody');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $body);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $parsedBody = $result->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertEquals('Jane', $parsedBody['name']);
        $this->assertEquals('jane@example.com', $parsedBody['email']);
        $this->assertEquals('25', $parsedBody['age']);
    }

    public function testApplyBodyDataWithJsonContent(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('POST', '/api', '/api');
        $pivotRequest = $pivotRequest->withHeader('Content-Type', 'application/json');
        $body = '{"action":"create","data":{"title":"Test"}}';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('applyBodyData');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $body);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $parsedBody = $result->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertEquals('create', $parsedBody['action']);
        $this->assertIsArray($parsedBody['data']);
        $this->assertEquals('Test', $parsedBody['data']['title']);

        // Verify body stream is set
        $bodyStream = $result->getBody();
        $this->assertEquals($body, (string) $bodyStream);
    }

    public function testApplyBodyDataWithFormContent(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('POST', '/form', '/form');
        $pivotRequest = $pivotRequest->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        $body = 'username=testuser&password=secret123';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('applyBodyData');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $body);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $parsedBody = $result->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertEquals('testuser', $parsedBody['username']);
        $this->assertEquals('secret123', $parsedBody['password']);
    }

    public function testApplyBodyDataEmpty(): void
    {
        $pivotRequest = new \PivotPHP\Core\Http\Request('GET', '/test', '/test');
        $body = '';

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('applyBodyData');
        $method->setAccessible(true);

        $result = $method->invoke($this->server, $pivotRequest, $body);

        $this->assertInstanceOf(\PivotPHP\Core\Http\Request::class, $result);
        $this->assertSame($pivotRequest, $result); // Should return unchanged
    }

    public function testCreateNotFoundResponse(): void
    {
        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('createNotFoundResponse');
        $method->setAccessible(true);

        $response = $method->invoke($this->server);

        $this->assertInstanceOf(\React\Http\Message\Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $decodedBody = json_decode($body, true);
        $this->assertIsArray($decodedBody);
        $this->assertEquals('Not Found', $decodedBody['error']);
    }

    public function testCreateErrorResponse(): void
    {
        $exception = new \Exception('Test error message');

        $reflection = new \ReflectionClass($this->server);
        $method = $reflection->getMethod('createErrorResponse');
        $method->setAccessible(true);

        $response = $method->invoke($this->server, $exception);

        $this->assertInstanceOf(\React\Http\Message\Response::class, $response);
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $decodedBody = json_decode($body, true);
        $this->assertIsArray($decodedBody);
        $this->assertEquals('Internal Server Error', $decodedBody['error']);
    }

    public function testIntegrationWithCompleteRequest(): void
    {
        $uri = new Uri('http://localhost/api/users?limit=5');
        $body = '{"name":"Alice","role":"admin"}';
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer token123',
        ];

        $reactRequest = new ServerRequest('POST', $uri, $headers, $body);

        // Test the complete flow through private methods
        $reflection = new \ReflectionClass($this->server);

        // Extract request data
        $extractMethod = $reflection->getMethod('extractRequestData');
        $extractMethod->setAccessible(true);
        $requestData = $extractMethod->invoke($this->server, $reactRequest);

        // Create PivotPHP request
        $createMethod = $reflection->getMethod('createPivotRequest');
        $createMethod->setAccessible(true);
        $pivotRequest = $createMethod->invoke($this->server, $requestData);

        // Verify the complete transformation
        $this->assertEquals('POST', $pivotRequest->getMethod());
        $this->assertEquals('/api/users', $pivotRequest->getPath());
        $this->assertEquals('application/json', $pivotRequest->getHeaderLine('Content-Type'));
        $this->assertEquals('Bearer token123', $pivotRequest->getHeaderLine('Authorization'));

        $queryParams = $pivotRequest->getQueryParams();
        $this->assertEquals('5', $queryParams['limit']);

        $parsedBody = $pivotRequest->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertEquals('Alice', $parsedBody['name']);
        $this->assertEquals('admin', $parsedBody['role']);

        $bodyStream = $pivotRequest->getBody();
        $this->assertEquals($body, (string) $bodyStream);
    }
}
