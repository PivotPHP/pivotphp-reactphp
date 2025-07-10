<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Bridge;

use Psr\Http\Message\ServerRequestInterface;
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

        self::assertEquals('GET', $pivotRequest->getMethod());
        self::assertEquals('/test', $pivotRequest->getUri()->getPath());
        $queryParams = $pivotRequest->getQueryParams();
        self::assertEquals('bar', $queryParams['foo']);
        self::assertEquals('application/json', $pivotRequest->getHeaderLine('Content-Type'));
    }

    public function testConvertRequestWithJsonBody(): void
    {
        $bodyContent = json_encode(['test' => 'data']);
        $bodyLength = $bodyContent !== false ? strlen($bodyContent) : 0;
        $bodyString = $bodyContent !== false ? $bodyContent : '{}';
        $reactRequest = new ReactServerRequest(
            'POST',
            'http://localhost/api',
            [
                'Content-Type' => 'application/json',
                'Content-Length' => (string) $bodyLength,
            ],
            $bodyString
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        /** @phpstan-ignore-next-line */
        self::assertEquals('POST', $pivotRequest->getMethod());
        /** @phpstan-ignore-next-line */
        self::assertEquals('/api', $pivotRequest->getUri()->getPath());
        $parsedBody = $pivotRequest->getParsedBody();
        if (is_array($parsedBody)) {
            /** @phpstan-ignore-next-line */
            self::assertEquals('data', $parsedBody['test']);
        } else {
            /** @phpstan-ignore-next-line */
            self::fail('Parsed body should be an array');
        }
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

        /** @phpstan-ignore-next-line */
        self::assertEquals('session=abc123; user=john', $pivotRequest->getHeaderLine('Cookie'));
        /** @phpstan-ignore-next-line */
        self::assertEquals('Bearer token123', $pivotRequest->getHeaderLine('Authorization'));
        /** @phpstan-ignore-next-line */
        self::assertEquals('custom-value', $pivotRequest->getHeaderLine('X-Custom-Header'));
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

        $parsedBody = $pivotRequest->getParsedBody();
        if (is_array($parsedBody)) {
            /** @phpstan-ignore-next-line */
            self::assertEquals('John', $parsedBody['name']);
            /** @phpstan-ignore-next-line */
            self::assertEquals(30, $parsedBody['age']);
        } else {
            /** @phpstan-ignore-next-line */
            self::fail('Parsed body should be an array');
        }
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

        /** @phpstan-ignore-next-line */
        self::assertEquals('GET', $pivotRequest->getMethod());
        /** @phpstan-ignore-next-line */
        self::assertEquals('/path', $pivotRequest->getUri()->getPath());
        $queryParams = $pivotRequest->getQueryParams();
        /** @phpstan-ignore-next-line */
        self::assertEquals('1', $queryParams['query']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('value', $pivotRequest->getHeaderLine('X-Custom-Header'));
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

        /** @phpstan-ignore-next-line */
        self::assertEquals('POST', $pivotRequest->getMethod());
        /** @phpstan-ignore-next-line */
        self::assertEquals('/form', $pivotRequest->getUri()->getPath());
        $parsedBody = $pivotRequest->getParsedBody();
        if (is_array($parsedBody)) {
            /** @phpstan-ignore-next-line */
            self::assertEquals('John', $parsedBody['name']);
            /** @phpstan-ignore-next-line */
            self::assertEquals('30', $parsedBody['age']);
            /** @phpstan-ignore-next-line */
            self::assertEquals('john@example.com', $parsedBody['email']);
        }
    }

    public function testConvertComplexRequest(): void
    {
        $bodyJson = json_encode(['name' => 'John Updated', 'email' => 'john@example.com']);
        $requestBody = $bodyJson !== false ? $bodyJson : '{}';

        $reactRequest = new ReactServerRequest(
            'PUT',
            'https://example.com:8443/api/users/123?include=profile&fields=name,email',
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer token123',
                'X-Custom-Header' => 'value'
            ],
            $requestBody,
            '1.1',
            ['REMOTE_ADDR' => '192.168.1.1', 'REMOTE_PORT' => '54321']
        );

        $pivotRequest = $this->bridge->convertFromReact($reactRequest);

        /** @phpstan-ignore-next-line */
        self::assertEquals('PUT', $pivotRequest->getMethod());
        /** @phpstan-ignore-next-line */
        self::assertEquals('/api/users/123', $pivotRequest->getUri()->getPath());
        $queryParams = $pivotRequest->getQueryParams();
        /** @phpstan-ignore-next-line */
        self::assertEquals('profile', $queryParams['include']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('name,email', $queryParams['fields']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('Bearer token123', $pivotRequest->getHeaderLine('Authorization'));
        $parsedBody = $pivotRequest->getParsedBody();
        /** @phpstan-ignore-next-line */
        self::assertEquals('John Updated', $parsedBody['name']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('john@example.com', $parsedBody['email']);
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

        $queryParams = $pivotRequest->getQueryParams();
        /** @phpstan-ignore-next-line */
        self::assertEquals('php', $queryParams['q']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('programming', $queryParams['category']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('date', $queryParams['sort']);
        /** @phpstan-ignore-next-line */
        self::assertEquals('desc', $queryParams['order']);
    }
}
