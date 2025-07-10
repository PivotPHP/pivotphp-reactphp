<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Mocks;

use PivotPHP\ReactPHP\Tests\TestCase;
use React\Http\Message\Response;
use React\EventLoop\Loop;

/**
 * Test cases demonstrating MockBrowser usage
 */
final class MockBrowserTest extends TestCase
{
    public function testMockBrowserBasicUsage(): void
    {
        $mockBrowser = new MockBrowser();

        // Set up a predefined response
        $expectedResponse = MockBrowser::createJsonResponse([
            'name' => 'pivotphp-core',
            'description' => 'A lightweight PHP microframework',
            'stars' => 100,
        ]);

        $mockBrowser->setResponse('https://api.github.com/repos/pivotphp/core', $expectedResponse);

        // Make the request
        $promise = $mockBrowser->get('https://api.github.com/repos/pivotphp/core');

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        // Wait for promise resolution
        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify the response
        self::assertNotNull($response);
        self::assertInstanceOf(Response::class, $response);
        self::assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertEquals('pivotphp-core', $body['name']);
        self::assertEquals(100, $body['stars']);

        // Verify the request was recorded
        $requests = $mockBrowser->getRequests();
        self::assertCount(1, $requests);
        self::assertEquals('GET', $requests[0]['method']);
        self::assertEquals('https://api.github.com/repos/pivotphp/core', $requests[0]['url']);
    }

    public function testMockBrowserPostRequest(): void
    {
        $mockBrowser = new MockBrowser();

        // Set up response for POST request
        $expectedResponse = MockBrowser::createJsonResponse([
            'created' => true,
            'id' => 123,
        ], 201);

        $mockBrowser->setResponse('https://api.example.com/users', $expectedResponse);

        // Make POST request
        $postData = json_encode(['name' => 'John Doe', 'email' => 'john@example.com']);
        $promise = $mockBrowser->post(
            'https://api.example.com/users',
            ['Content-Type' => 'application/json'],
            $postData
        );

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify response
        self::assertNotNull($response);
        self::assertEquals(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['created']);
        self::assertEquals(123, $body['id']);

        // Verify request was recorded with body
        $requests = $mockBrowser->getRequests();
        self::assertCount(1, $requests);
        self::assertEquals('POST', $requests[0]['method']);
        self::assertEquals($postData, $requests[0]['body']);
        self::assertEquals('application/json', $requests[0]['headers']['Content-Type']);
    }

    public function testMockBrowserErrorResponse(): void
    {
        $mockBrowser = new MockBrowser();

        // Set up an error response
        $errorResponse = MockBrowser::createErrorResponse('Not found', 404);
        $mockBrowser->setResponse('https://api.example.com/nonexistent', $errorResponse);

        // Make request
        $promise = $mockBrowser->get('https://api.example.com/nonexistent');

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify error response
        self::assertNotNull($response);
        self::assertEquals(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertEquals('Not found', $body['error']);
    }

    public function testMockBrowserException(): void
    {
        $mockBrowser = new MockBrowser();

        // Set up an exception
        $exception = new \RuntimeException('Network error');
        $mockBrowser->setError('https://api.example.com/error', $exception);

        // Make request
        $promise = $mockBrowser->get('https://api.example.com/error');

        $error = null;
        $promise->then(
            function ($response) {
                self::fail('Should not reach success handler');
            },
            function ($err) use (&$error) {
                $error = $err;
            }
        );

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify exception was thrown
        self::assertNotNull($error);
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertEquals('Network error', $error->getMessage());
    }

    public function testMockBrowserParallelRequests(): void
    {
        $mockBrowser = new MockBrowser();

        // Set up multiple responses
        $responses = [
            'https://api.github.com' => MockBrowser::createJsonResponse(['service' => 'github']),
            'http://worldtimeapi.org/api/timezone/UTC' => MockBrowser::createJsonResponse(['timezone' => 'UTC']),
        ];

        $mockBrowser->setResponses($responses);

        // Make parallel requests
        $promises = [
            'github' => $mockBrowser->get('https://api.github.com'),
            'time' => $mockBrowser->get('http://worldtimeapi.org/api/timezone/UTC'),
        ];

        $results = [];
        \React\Promise\all($promises)->then(function ($responses) use (&$results) {
            $results = $responses;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify both requests completed
        self::assertCount(2, $results);
        self::assertArrayHasKey('github', $results);
        self::assertArrayHasKey('time', $results);

        // Verify responses
        $githubBody = json_decode((string) $results['github']->getBody(), true);
        self::assertEquals('github', $githubBody['service']);

        $timeBody = json_decode((string) $results['time']->getBody(), true);
        self::assertEquals('UTC', $timeBody['timezone']);

        // Verify both requests were recorded
        $requests = $mockBrowser->getRequests();
        self::assertCount(2, $requests);
    }

    public function testMockBrowserConfiguration(): void
    {
        $mockBrowser = new MockBrowser();

        // Test configuration methods
        $configuredBrowser = $mockBrowser
            ->withTimeout(60.0)
            ->withFollowRedirects(false)
            ->withHeader('User-Agent', 'MockBrowser/1.0')
            ->withRejectErrorResponse(false);

        $config = $configuredBrowser->getConfiguration();

        self::assertEquals(60.0, $config['timeout']);
        self::assertFalse($config['followRedirects']);
        self::assertFalse($config['rejectErrorResponse']);
        self::assertEquals('MockBrowser/1.0', $config['defaultHeaders']['User-Agent']);

        // Original browser should be unchanged
        $originalConfig = $mockBrowser->getConfiguration();
        self::assertEquals(30.0, $originalConfig['timeout']);
        self::assertTrue($originalConfig['followRedirects']);
        self::assertEmpty($originalConfig['defaultHeaders']);
    }

    public function testMockBrowserDefaultResponse(): void
    {
        $mockBrowser = new MockBrowser();

        // Request to URL without predefined response
        $promise = $mockBrowser->get('https://example.com/unknown');

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
        });

        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Should get default response
        self::assertNotNull($response);
        self::assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['mock']);
        self::assertEquals('https://example.com/unknown', $body['url']);
        self::assertEquals('GET', $body['method']);
    }
}
