<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Integration;

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\ReactPHP\Tests\Helpers\JsonHelper;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;
use React\Promise\Promise;

/**
 * Test to verify that the ReactServer handles concurrent requests safely
 * without race conditions between simultaneous requests modifying global state
 */
final class ConcurrencySafetyTest extends TestCase
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

        // Route that returns the POST body data
        $router::post('/echo', function ($request, $response) {
            $body = $request->body;
            return (new Response())->json([
                'received' => json_decode(json_encode($body), true),
                'request_id' => $request->getAttribute('request_id', 'unknown'),
            ]);
        });

        // Route that simulates processing delay
        $router::post('/slow-echo', function ($request, $response) {
            // Simulate some processing time
            usleep(10000); // 10ms
            $body = $request->body;
            return (new Response())->json([
                'received' => json_decode(json_encode($body), true),
                'request_id' => $request->getAttribute('request_id', 'unknown'),
            ]);
        });
    }

    public function testConcurrentPostRequestsDoNotInterfere(): void
    {
        $promises = [];
        $responses = [];

        // Create multiple concurrent POST requests with different data
        $requests = [
            ['id' => 1, 'data' => 'request-1-data'],
            ['id' => 2, 'data' => 'request-2-data'],
            ['id' => 3, 'data' => 'request-3-data'],
            ['id' => 4, 'data' => 'request-4-data'],
            ['id' => 5, 'data' => 'request-5-data'],
        ];

        foreach ($requests as $index => $requestData) {
            $request = (new ServerRequest(
                'POST',
                new Uri('http://localhost/echo'),
                ['Content-Type' => 'application/json']
            ))->withBody(
                new \PivotPHP\Core\Http\Psr7\Stream(JsonHelper::encode($requestData))
            )->withAttribute('request_id', 'req-' . $index);

            $promise = $this->server->handleRequest($request);
            $promise->then(function ($response) use ($index, &$responses) {
                $responses[$index] = $response;
            });

            $promises[] = $promise;
        }

        // Wait for all promises to complete
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

        // Verify all responses are correct and no data was mixed up
        self::assertCount(5, $responses);

        foreach ($responses as $index => $response) {
            assert($response instanceof \React\Http\Message\Response);
            self::assertEquals(200, $response->getStatusCode());

            $body = JsonHelper::decode((string) $response->getBody());
            self::assertArrayHasKey('received', $body);
            self::assertArrayHasKey('id', $body['received']);
            self::assertArrayHasKey('data', $body['received']);

            // Verify each response contains its own data, not mixed with others
            self::assertEquals($index + 1, $body['received']['id']);
            self::assertEquals('request-' . ($index + 1) . '-data', $body['received']['data']);
        }
    }

    public function testConcurrentPostRequestsWithProcessingDelay(): void
    {
        $promises = [];
        $responses = [];

        // Create multiple concurrent POST requests with different data and processing delay
        $requests = [
            ['id' => 'A', 'payload' => 'data-A', 'timestamp' => microtime(true)],
            ['id' => 'B', 'payload' => 'data-B', 'timestamp' => microtime(true)],
            ['id' => 'C', 'payload' => 'data-C', 'timestamp' => microtime(true)],
        ];

        foreach ($requests as $index => $requestData) {
            $request = (new ServerRequest(
                'POST',
                new Uri('http://localhost/slow-echo'),
                ['Content-Type' => 'application/json']
            ))->withBody(
                new \PivotPHP\Core\Http\Psr7\Stream(JsonHelper::encode($requestData))
            )->withAttribute('request_id', 'slow-req-' . $index);

            $promise = $this->server->handleRequest($request);
            $promise->then(function ($response) use ($index, &$responses) {
                $responses[$index] = $response;
            });

            $promises[] = $promise;
        }

        // Wait for all promises to complete
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

        // Verify all responses are correct and no data was mixed up even with processing delay
        self::assertCount(3, $responses);

        foreach ($responses as $index => $response) {
            assert($response instanceof \React\Http\Message\Response);
            self::assertEquals(200, $response->getStatusCode());

            $body = JsonHelper::decode((string) $response->getBody());
            self::assertArrayHasKey('received', $body);
            self::assertArrayHasKey('id', $body['received']);
            self::assertArrayHasKey('payload', $body['received']);

            // Verify each response contains its own data, not mixed with others
            $expectedId = ['A', 'B', 'C'][$index];
            self::assertEquals($expectedId, $body['received']['id']);
            self::assertEquals('data-' . $expectedId, $body['received']['payload']);
        }
    }

    public function testGlobalStateIsolation(): void
    {
        // This test verifies that global state modifications are properly isolated
        // by checking that $_POST, $_GET, and $_SERVER are not affected by concurrent requests

        // Store original state
        $originalPost = $_POST;
        $originalGet = $_GET;
        $originalServer = $_SERVER;

        // Create a request that would modify global state
        $request = (new ServerRequest(
            'POST',
            new Uri('http://localhost/echo?test=value'),
            ['Content-Type' => 'application/json']
        ))->withBody(
            new \PivotPHP\Core\Http\Psr7\Stream(JsonHelper::encode(['test' => 'data']))
        );

        $promise = $this->server->handleRequest($request);
        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        // Run event loop
        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify the request was processed successfully
        self::assertNotNull($response);
        assert($response instanceof \React\Http\Message\Response);
        self::assertEquals(200, $response->getStatusCode());

        // Verify global state was not permanently modified
        self::assertEquals($originalPost, $_POST);
        self::assertEquals($originalGet, $_GET);
        self::assertEquals($originalServer, $_SERVER);
    }
}
