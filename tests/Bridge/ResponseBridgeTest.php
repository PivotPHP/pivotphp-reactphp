<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Bridge;

use PivotPHP\ReactPHP\Bridge\ResponseBridge;
use PivotPHP\ReactPHP\Tests\TestCase;
use React\Http\Message\Response as ReactResponse;

final class ResponseBridgeTest extends TestCase
{
    private ResponseBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new ResponseBridge();
    }

    public function testConvertBasicResponse(): void
    {
        $psrResponse = $this->responseFactory->createResponse(200, 'OK')
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->streamFactory->createStream('Hello, World!'));

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        self::assertEquals(200, $reactResponse->getStatusCode());
        self::assertEquals('OK', $reactResponse->getReasonPhrase());
        self::assertEquals('text/plain', $reactResponse->getHeaderLine('Content-Type'));
        self::assertEquals('Hello, World!', (string) $reactResponse->getBody());
    }

    public function testConvertResponseWithMultipleHeaders(): void
    {
        $psrResponse = $this->responseFactory->createResponse(201)
            ->withHeader('X-Custom', 'value1')
            ->withAddedHeader('X-Custom', 'value2')
            ->withHeader('Cache-Control', 'no-cache');

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        self::assertEquals('value1, value2', $reactResponse->getHeaderLine('X-Custom'));
        self::assertEquals('no-cache', $reactResponse->getHeaderLine('Cache-Control'));
    }

    public function testConvertEmptyResponse(): void
    {
        $psrResponse = $this->responseFactory->createResponse(204);

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        self::assertEquals(204, $reactResponse->getStatusCode());
        self::assertEquals('', (string) $reactResponse->getBody());
    }

    public function testConvertJsonResponse(): void
    {
        $data = ['status' => 'success', 'data' => ['id' => 1, 'name' => 'Test']];
        $json = json_encode($data);
        $jsonString = $json !== false ? $json : '{}';

        $psrResponse = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($jsonString));

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        self::assertEquals('application/json', $reactResponse->getHeaderLine('Content-Type'));
        self::assertEquals($jsonString, (string) $reactResponse->getBody());

        $decoded = json_decode((string) $reactResponse->getBody(), true);
        self::assertEquals($data, $decoded);
    }

    public function testConvertResponseWithCustomStatusAndProtocol(): void
    {
        $psrResponse = $this->responseFactory->createResponse(418, "I'm a teapot")
            ->withProtocolVersion('2.0');

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        self::assertEquals(418, $reactResponse->getStatusCode());
        self::assertEquals("I'm a teapot", $reactResponse->getReasonPhrase());
        self::assertEquals('2.0', $reactResponse->getProtocolVersion());
    }

    public function testConvertLargeResponse(): void
    {
        $largeContent = str_repeat('Lorem ipsum dolor sit amet. ', 1000);

        $psrResponse = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Length', (string) strlen($largeContent))
            ->withBody($this->streamFactory->createStream($largeContent));

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        self::assertEquals($largeContent, (string) $reactResponse->getBody());
        self::assertEquals(strlen($largeContent), $reactResponse->getHeaderLine('Content-Length'));
    }
}
