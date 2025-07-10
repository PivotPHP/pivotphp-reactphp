<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Bridge;

use PivotPHP\ReactPHP\Bridge\RequestBridge;
use PivotPHP\ReactPHP\Tests\TestCase;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;
use React\Stream\ThroughStream;
use Psr\Http\Message\ServerRequestInterface;

final class RequestBridgeUpdatedTest extends TestCase
{
    private RequestBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new RequestBridge();
    }

    public function testConvertFromReactReturnsPsr7ServerRequest(): void
    {
        $reactRequest = new ServerRequest(
            'GET',
            new Uri('http://example.com/test')
        );

        $result = $this->bridge->convertFromReact($reactRequest);

        self::assertEquals('GET', $result->getMethod());
        self::assertEquals('/test', $result->getUri()->getPath());
    }

    public function testConvertFromReactWithHeaders(): void
    {
        $reactRequest = new ServerRequest(
            'POST',
            new Uri('http://example.com/api/users'),
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer token123',
                'X-Custom-Header' => 'custom-value',
            ]
        );

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        self::assertEquals('POST', $pivotRequest->getMethod());
        self::assertEquals('/api/users', $pivotRequest->getUri()->getPath());
        self::assertEquals(['application/json'], $pivotRequest->getHeader('Content-Type'));
        self::assertEquals(['Bearer token123'], $pivotRequest->getHeader('Authorization'));
        self::assertEquals(['custom-value'], $pivotRequest->getHeader('X-Custom-Header'));
    }

    public function testConvertFromReactWithJsonBody(): void
    {
        $body = ['name' => 'Test User', 'email' => 'test@example.com'];

        $reactRequest = (new ServerRequest(
            'POST',
            new Uri('http://example.com/api/users'),
            ['Content-Type' => 'application/json']
        ))->withParsedBody($body);

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        $parsedBody = $pivotRequest->getParsedBody();
        self::assertEquals($body, $parsedBody);
    }

    public function testConvertFromReactWithQueryParams(): void
    {
        $reactRequest = new ServerRequest(
            'GET',
            new Uri('http://example.com/search?param=test&id=123')
        );

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        $queryParams = $pivotRequest->getQueryParams();
        self::assertEquals('test', $queryParams['param']);
        self::assertEquals('123', $queryParams['id']);
    }

    public function testConvertFromReactWithCookies(): void
    {
        $reactRequest = (new ServerRequest(
            'GET',
            new Uri('http://example.com/test')
        ))->withCookieParams([
            'PHPSESSID' => 'session123',
            'custom' => 'value',
        ]);

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        $cookies = $pivotRequest->getCookieParams();
        self::assertEquals('session123', $cookies['PHPSESSID']);
        self::assertEquals('value', $cookies['custom']);
    }

    public function testConvertFromReactWithUploadedFiles(): void
    {
        $uploadedFile = $this->createMock(\Psr\Http\Message\UploadedFileInterface::class);
        $uploadedFile->method('getSize')->willReturn(1024);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);

        $reactRequest = (new ServerRequest(
            'POST',
            new Uri('http://example.com/upload')
        ))->withUploadedFiles([
            'file' => $uploadedFile
        ]);

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        $files = $pivotRequest->getUploadedFiles();
        self::assertArrayHasKey('file', $files);
        self::assertInstanceOf(\Psr\Http\Message\UploadedFileInterface::class, $files['file']);
    }

    public function testConvertFromReactWithAttributes(): void
    {
        $reactRequest = (new ServerRequest(
            'GET',
            new Uri('http://example.com/test')
        ))
        ->withAttribute('user_id', 123)
        ->withAttribute('session', ['data' => 'test'])
        ->withAttribute('route', ['name' => 'test.route']);

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        self::assertEquals(123, $pivotRequest->getAttribute('user_id'));
        self::assertEquals(['data' => 'test'], $pivotRequest->getAttribute('session'));
        self::assertEquals(['name' => 'test.route'], $pivotRequest->getAttribute('route'));
    }

    public function testConvertFromReactWithStreamBody(): void
    {
        $bodyContent = '{"test": "data", "number": 42}';

        $reactRequest = new ServerRequest(
            'POST',
            new Uri('http://example.com/stream'),
            ['Content-Type' => 'application/json'],
            $bodyContent
        );

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        $body = $pivotRequest->getBody();
        $contents = (string) $body;
        self::assertEquals('{"test": "data", "number": 42}', $contents);

        // Test that body can be rewound and read again
        if ($body->isSeekable()) {
            $body->rewind();
            self::assertEquals('{"test": "data", "number": 42}', (string) $body);
        }
    }

    public function testConvertFromReactPreservesServerParams(): void
    {
        $serverParams = [
            'REMOTE_ADDR' => '192.168.1.100',
            'REMOTE_PORT' => '54321',
            'SERVER_SOFTWARE' => 'ReactPHP/1.0',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        $reactRequest = new ServerRequest(
            'GET',
            new Uri('https://example.com:8443/test?foo=bar'),
            ['Host' => 'example.com:8443'],
            '',
            '1.1',
            $serverParams
        );

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        $resultParams = $pivotRequest->getServerParams();

        // Check that server params are preserved and enhanced
        self::assertArrayHasKey('REQUEST_METHOD', $resultParams);
        self::assertEquals('GET', $resultParams['REQUEST_METHOD']);
        self::assertArrayHasKey('REQUEST_URI', $resultParams);
        self::assertEquals('/test?foo=bar', $resultParams['REQUEST_URI']);
        self::assertArrayHasKey('QUERY_STRING', $resultParams);
        self::assertEquals('foo=bar', $resultParams['QUERY_STRING']);
        self::assertArrayHasKey('HTTPS', $resultParams);
        self::assertEquals('on', $resultParams['HTTPS']);
        self::assertArrayHasKey('SERVER_PORT', $resultParams);
        self::assertEquals(8443, $resultParams['SERVER_PORT']);
    }

    public function testConvertFromReactWithProtocolVersion(): void
    {
        $reactRequest = new ServerRequest(
            'GET',
            new Uri('http://example.com/test'),
            [],
            '',
            '2.0' // HTTP/2
        );

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        self::assertEquals('2.0', $pivotRequest->getProtocolVersion());
    }

    public function testConvertFromReactWithRequestTarget(): void
    {
        $reactRequest = (new ServerRequest(
            'GET',
            new Uri('http://example.com/test?foo=bar')
        ))->withRequestTarget('/test?foo=bar&custom=1');

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        self::assertEquals('/test?foo=bar&custom=1', $pivotRequest->getRequestTarget());
    }

    public function testConvertFromReactWithMultipleHeaderValues(): void
    {
        $reactRequest = new ServerRequest(
            'GET',
            new Uri('http://example.com/test'),
            [
                'Accept' => ['application/json', 'text/html'],
                'Accept-Language' => ['en-US', 'en;q=0.9', 'fr;q=0.8'],
            ]
        );

        $bridge = new RequestBridge();
        $pivotRequest = $bridge->convertFromReact($reactRequest);

        self::assertEquals(['application/json', 'text/html'], $pivotRequest->getHeader('Accept'));
        self::assertEquals(['en-US', 'en;q=0.9', 'fr;q=0.8'], $pivotRequest->getHeader('Accept-Language'));
    }
}
