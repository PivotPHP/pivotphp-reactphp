<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Bridge;

use PivotPHP\Core\Http\Request as PivotRequest;
use PivotPHP\ReactPHP\Bridge\RequestBridge;
use PivotPHP\ReactPHP\Tests\TestCase;
use React\Http\Message\ServerRequest as ReactServerRequest;

final class RequestBridgeTest extends TestCase
{
    private RequestBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new RequestBridge();
    }

    public function testConvertBasicRequest(): void
    {
        $reactRequest = new ReactServerRequest(
            'GET',
            'http://localhost/test?foo=bar',
            ['Content-Type' => 'application/json'],
            '',
            '1.1',
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('GET', $pivotRequest->getMethod());
        $this->assertEquals('/test', $pivotRequest->getPath());
        $this->assertEquals('bar', $pivotRequest->query->foo);
        $this->assertEquals('application/json', $pivotRequest->header('contentType'));
    }

    public function testConvertRequestWithJsonBody(): void
    {
        $bodyContent = json_encode(['test' => 'data']);
        $reactRequest = new ReactServerRequest(
            'POST',
            'http://localhost/api',
            [
                'Content-Type' => 'application/json',
                'Content-Length' => (string) strlen($bodyContent),
            ],
            $bodyContent
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('POST', $pivotRequest->getMethod());
        $this->assertEquals('/api', $pivotRequest->getPath());
        $this->assertEquals('data', $pivotRequest->body->test);
    }

    public function testConvertRequestWithHeaders(): void
    {
        $reactRequest = new ReactServerRequest(
            'GET',
            'http://localhost/',
            [
                'Cookie' => 'session=abc123; user=john',
                'Authorization' => 'Bearer token123',
                'X-Custom-Header' => 'custom-value'
            ],
            '',
            '1.1',
            []
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('session=abc123; user=john', $pivotRequest->header('cookie'));
        $this->assertEquals('Bearer token123', $pivotRequest->header('authorization'));
        $this->assertEquals('custom-value', $pivotRequest->header('xCustomHeader'));
    }

    public function testConvertRequestWithParsedBody(): void
    {
        $parsedBody = ['name' => 'John', 'age' => 30];
        $reactRequest = new ReactServerRequest(
            'POST',
            'http://localhost/form',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            ''
        );

        $reactRequest = $reactRequest->withParsedBody($parsedBody);

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('John', $pivotRequest->body->name);
        $this->assertEquals(30, $pivotRequest->body->age);
    }

    public function testServerParamsConversion(): void
    {
        $reactRequest = new ReactServerRequest(
            'GET',
            'https://example.com:8443/path?query=1',
            ['X-Custom-Header' => 'value'],
            '',
            '1.1',
            ['REMOTE_ADDR' => '192.168.1.1', 'REMOTE_PORT' => '54321']
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('GET', $pivotRequest->getMethod());
        $this->assertEquals('/path', $pivotRequest->getPath());
        $this->assertEquals('1', $pivotRequest->query->query);
        $this->assertEquals('value', $pivotRequest->header('xCustomHeader'));
    }

    public function testConvertRequestWithFormEncodedBody(): void
    {
        $bodyContent = 'name=John&age=30&email=john%40example.com';
        $reactRequest = new ReactServerRequest(
            'POST',
            'http://localhost/form',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $bodyContent
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('POST', $pivotRequest->getMethod());
        $this->assertEquals('/form', $pivotRequest->getPath());
        $this->assertEquals('John', $pivotRequest->body->name);
        $this->assertEquals('30', $pivotRequest->body->age);
        $this->assertEquals('john@example.com', $pivotRequest->body->email);
    }

    public function testConvertComplexRequest(): void
    {
        $reactRequest = new ReactServerRequest(
            'PUT',
            'https://example.com:8443/api/users/123?include=profile&fields=name,email',
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer token123',
                'X-Custom-Header' => 'value'
            ],
            json_encode(['name' => 'John Updated', 'email' => 'john@example.com']),
            '1.1',
            ['REMOTE_ADDR' => '192.168.1.1', 'REMOTE_PORT' => '54321']
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('PUT', $pivotRequest->getMethod());
        $this->assertEquals('/api/users/123', $pivotRequest->getPath());
        $this->assertEquals('profile', $pivotRequest->query->include);
        $this->assertEquals('name,email', $pivotRequest->query->fields);
        $this->assertEquals('Bearer token123', $pivotRequest->header('authorization'));
        $this->assertEquals('John Updated', $pivotRequest->body->name);
        $this->assertEquals('john@example.com', $pivotRequest->body->email);
    }

    public function testConvertRequestWithMultipleQueryParams(): void
    {
        $reactRequest = new ReactServerRequest(
            'GET',
            'http://localhost/search?q=php&category=programming&sort=date&order=desc',
            [],
            ''
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        $this->assertEquals('php', $pivotRequest->query->q);
        $this->assertEquals('programming', $pivotRequest->query->category);
        $this->assertEquals('date', $pivotRequest->query->sort);
        $this->assertEquals('desc', $pivotRequest->query->order);
    }
}
