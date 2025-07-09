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

        $this->assertInstanceOf(ReactResponse::class, $reactResponse);
        $this->assertEquals(200, $reactResponse->getStatusCode());
        $this->assertEquals('OK', $reactResponse->getReasonPhrase());
        $this->assertEquals('text/plain', $reactResponse->getHeaderLine('Content-Type'));
        $this->assertEquals('Hello, World!', (string) $reactResponse->getBody());
    }

    public function testConvertResponseWithMultipleHeaders(): void
    {
        $psrResponse = $this->responseFactory->createResponse(201)
            ->withHeader('X-Custom', 'value1')
            ->withAddedHeader('X-Custom', 'value2')
            ->withHeader('Cache-Control', 'no-cache');

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        $this->assertEquals('value1, value2', $reactResponse->getHeaderLine('X-Custom'));
        $this->assertEquals('no-cache', $reactResponse->getHeaderLine('Cache-Control'));
    }

    public function testConvertEmptyResponse(): void
    {
        $psrResponse = $this->responseFactory->createResponse(204);

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        $this->assertEquals(204, $reactResponse->getStatusCode());
        $this->assertEquals('', (string) $reactResponse->getBody());
    }

    public function testConvertJsonResponse(): void
    {
        $data = ['status' => 'success', 'data' => ['id' => 1, 'name' => 'Test']];
        $json = json_encode($data);

        $psrResponse = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($json));

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        $this->assertEquals('application/json', $reactResponse->getHeaderLine('Content-Type'));
        $this->assertEquals($json, (string) $reactResponse->getBody());

        $decoded = json_decode((string) $reactResponse->getBody(), true);
        $this->assertEquals($data, $decoded);
    }

    public function testConvertResponseWithCustomStatusAndProtocol(): void
    {
        $psrResponse = $this->responseFactory->createResponse(418, "I'm a teapot")
            ->withProtocolVersion('2.0');

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        $this->assertEquals(418, $reactResponse->getStatusCode());
        $this->assertEquals("I'm a teapot", $reactResponse->getReasonPhrase());
        $this->assertEquals('2.0', $reactResponse->getProtocolVersion());
    }

    public function testConvertLargeResponse(): void
    {
        $largeContent = str_repeat('Lorem ipsum dolor sit amet. ', 1000);

        $psrResponse = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Length', (string) strlen($largeContent))
            ->withBody($this->streamFactory->createStream($largeContent));

        $reactResponse = $this->bridge->convertToReact($psrResponse);

        $this->assertEquals($largeContent, (string) $reactResponse->getBody());
        $this->assertEquals(strlen($largeContent), $reactResponse->getHeaderLine('Content-Length'));
    }
}
