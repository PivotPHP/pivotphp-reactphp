<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Integration;

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Bridge\RequestBridge;
use PivotPHP\ReactPHP\Bridge\ResponseBridge;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\ReactPHP\Tests\Helpers\JsonHelper;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;
use React\Promise\Promise;
use React\Socket\SocketServer;

final class ReactServerIntegrationTest extends TestCase
{
    protected Application $app;
    private ReactServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Create application
        $this->app = new Application(__DIR__);

        // Create server with test configuration
        $this->server = new ReactServer(
            $this->app,
            Loop::get(),
            null,
            [
                'debug' => true,
                'streaming' => false,
                'max_concurrent_requests' => 10,
            ]
        );

        // Setup test routes
        $this->setupTestRoutes();
    }

    protected function tearDown(): void
    {
        // Ensure server is stopped
        $this->server->stop();
        parent::tearDown();
    }

    private function setupTestRoutes(): void
    {
        $router = $this->app->make(Router::class);
        assert($router instanceof Router);

        // Basic route
        $router::get('/', function ($request, $response) {
            return (new Response())->json(['message' => 'Hello from ReactPHP']);
        });

        // Route with parameters (PivotPHP syntax: :id not {id})
        $router::get('/user/:id', function ($request, $response) {
            $id = $request->param('id');
            return (new Response())->json([
                'user_id' => $id,
                'timestamp' => time(),
            ]);
        });

        // POST route
        $router::post('/api/data', function ($request, $response) {
            // Access the request body (PivotPHP automatically parses JSON)
            $body = $request->body;

            // Convert stdClass to array for response
            $bodyArray = json_decode(json_encode($body), true);

            return (new Response())->json([
                'received' => $bodyArray,
                'processed' => true,
            ]);
        });

        // Error route
        $router::get('/error', function ($request, $response) {
            throw new \RuntimeException('Test error');
        });

        // Streaming route
        $router::get('/stream', function ($request, $response) {
            return (new Response())->withBody(new \PivotPHP\Core\Http\Psr7\Stream(str_repeat('x', 10000)))
                ->withHeader('X-Stream-Response', 'true');
        });
    }

    public function testServerHandlesBasicRequest(): void
    {
        $request = new ServerRequest(
            'GET',
            new Uri('http://localhost/')
        );

        $promise = $this->server->handleRequest($request);

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        // Run event loop briefly to process promise
        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        self::assertNotNull($response);
        assert($response instanceof \React\Http\Message\Response);
        self::assertEquals(200, $response->getStatusCode());

        $body = JsonHelper::decode((string) $response->getBody());
        self::assertEquals('Hello from ReactPHP', $body['message']);
    }

    public function testServerHandlesRouteParameters(): void
    {
        $request = new ServerRequest(
            'GET',
            new Uri('http://localhost/user/123')
        );

        $promise = $this->server->handleRequest($request);

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        self::assertNotNull($response);
        assert($response instanceof \React\Http\Message\Response);

        // With corrected PivotPHP route syntax, this should work now
        self::assertEquals(200, $response->getStatusCode());

        $body = JsonHelper::decode((string) $response->getBody());
        self::assertNotNull($body, 'Route should return valid JSON response');

        self::assertEquals('123', $body['user_id']);
        self::assertArrayHasKey('timestamp', $body);
    }

    public function testServerHandlesPostRequest(): void
    {
        $postData = ['name' => 'Test', 'value' => 42];

        $request = (new ServerRequest(
            'POST',
            new Uri('http://localhost/api/data'),
            ['Content-Type' => 'application/json']
        ))->withBody(
            new \PivotPHP\Core\Http\Psr7\Stream(JsonHelper::encode($postData))
        );

        $promise = $this->server->handleRequest($request);

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        self::assertNotNull($response);
        assert($response instanceof \React\Http\Message\Response);
        self::assertEquals(200, $response->getStatusCode());

        $body = JsonHelper::decode((string) $response->getBody());

        self::assertNotNull($body, 'Response body should contain valid JSON');
        self::assertArrayHasKey('received', $body, 'Response should contain received data');
        self::assertArrayHasKey('processed', $body, 'Response should contain processed flag');
        self::assertEquals($postData, $body['received']);
        self::assertTrue($body['processed']);
    }

    public function testServerHandlesErrors(): void
    {
        $request = new ServerRequest(
            'GET',
            new Uri('http://localhost/error')
        );

        $promise = $this->server->handleRequest($request);

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        self::assertNotNull($response);
        assert($response instanceof \React\Http\Message\Response);
        self::assertEquals(500, $response->getStatusCode());

        $body = JsonHelper::decode((string) $response->getBody());
        self::assertEquals('Internal Server Error', $body['error']);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('error_id', $body);
    }

    public function testServerDetectsStreamingResponse(): void
    {
        $request = new ServerRequest(
            'GET',
            new Uri('http://localhost/stream')
        );

        $promise = $this->server->handleRequest($request);

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        self::assertNotNull($response);
        assert($response instanceof \React\Http\Message\Response);
        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('true', $response->getHeaderLine('X-Stream-Response'));
    }

    public function testServerHandlesMultipleConcurrentRequests(): void
    {
        $promises = [];
        $responses = [];

        // Create 5 concurrent requests
        for ($i = 1; $i <= 5; $i++) {
            $request = new ServerRequest(
                'GET',
                new Uri("http://localhost/user/$i")
            );

            $promise = $this->server->handleRequest($request);
            $promise->then(function ($response) use ($i, &$responses) {
                $responses[$i] = $response;
            });

            $promises[] = $promise;
        }

        // Wait for all promises
        $all = \React\Promise\all($promises);
        $completed = false;
        $all->then(function () use (&$completed) {
            $completed = true;
        });

        // Run event loop until all complete
        Loop::get()->futureTick(function () use (&$completed) {
            if ($completed) {
                Loop::get()->stop();
            }
        });
        Loop::get()->run();

        self::assertCount(5, $responses);

        foreach ($responses as $i => $response) {
            assert($response instanceof \React\Http\Message\Response);
            self::assertEquals(200, $response->getStatusCode());
            $body = JsonHelper::decode((string) $response->getBody());
            self::assertEquals((string) $i, $body['user_id']);
        }
    }

    public function testServerStartAndStop(): void
    {
        // Test starting server (without actually binding to port)
        $this->expectNotToPerformAssertions();

        // Server should be able to stop gracefully
        $this->server->stop();

        // Should be able to stop multiple times without error
        $this->server->stop();
    }

    public function testServerLogsRequests(): void
    {
        $logs = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('info')
            ->with(self::stringContains('Request handled'))
            ->willReturnCallback(function ($message, $context) use (&$logs) {
                $logs[] = ['message' => $message, 'context' => $context];
            });

        $server = new ReactServer($this->app, Loop::get(), $logger);

        $request = new ServerRequest('GET', new Uri('http://localhost/'));
        $promise = $server->handleRequest($request);

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        self::assertNotEmpty($logs);
        $log = $logs[0];
        self::assertArrayHasKey('method', $log['context']);
        self::assertArrayHasKey('uri', $log['context']);
        self::assertArrayHasKey('status', $log['context']);
        self::assertArrayHasKey('duration_ms', $log['context']);
    }

    public function testBridgeIntegration(): void
    {
        // Test that bridges are working correctly
        $requestBridge = new RequestBridge();
        $responseBridge = new ResponseBridge();

        // Create a React request
        $reactRequest = new ServerRequest(
            'POST',
            new Uri('http://localhost/test'),
            ['Content-Type' => 'application/json'],
            '{"test": true}'
        );

        // Convert to PSR-7
        $psrRequest = $requestBridge->convertFromReact($reactRequest);

        // Create a response
        $psrResponse = (new Response())->json(['success' => true]);

        // Convert back to React
        $reactResponse = $responseBridge->convertToReact($psrResponse);
        self::assertEquals(200, $reactResponse->getStatusCode());
    }
}
