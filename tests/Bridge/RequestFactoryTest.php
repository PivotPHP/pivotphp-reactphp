<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Bridge;

use PivotPHP\ReactPHP\Bridge\RequestFactory;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\Core\Http\Request as PivotRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class RequestFactoryTest extends TestCase
{
    private RequestFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = RequestFactory::create();
    }

    public function testCreateBasicRequest(): void
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest('GET', '/test');

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('GET', $pivotRequest->getMethod());
        $this->assertEquals('/test', $pivotRequest->getPath());
    }

    public function testCreateRequestWithHeaders(): void
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/api/test')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer token123')
            ->withHeader('X-Custom-Header', 'custom-value');

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('POST', $pivotRequest->getMethod());

        // Test header access through PivotPHP's HeaderRequest object
        $headers = $pivotRequest->getHeadersObject();
        $this->assertNotNull($headers);
        $this->assertEquals('application/json', $headers->contentType());
        $this->assertEquals('Bearer token123', $headers->authorization());
    }

    public function testCreateRequestWithQueryParams(): void
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest('GET', '/search')
            ->withQueryParams(['q' => 'test', 'limit' => '10']);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('test', $pivotRequest->get('q'));
        $this->assertEquals('10', $pivotRequest->get('limit'));
        $this->assertNull($pivotRequest->get('nonexistent'));
    }

    public function testCreateRequestWithJsonBody(): void
    {
        $body = $this->streamFactory->createStream('{"name":"John","email":"john@example.com"}');
        $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/users')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('John', $pivotRequest->input('name'));
        $this->assertEquals('john@example.com', $pivotRequest->input('email'));
    }

    public function testCreateRequestWithFormData(): void
    {
        $body = $this->streamFactory->createStream('name=Jane&email=jane@example.com');
        $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/submit')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($body);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('Jane', $pivotRequest->input('name'));
        $this->assertEquals('jane@example.com', $pivotRequest->input('email'));
    }

    public function testCreateRequestWithParsedBody(): void
    {
        $parsedBody = ['action' => 'create', 'data' => ['title' => 'Test']];
        $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/action')
            ->withParsedBody($parsedBody);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('create', $pivotRequest->input('action'));
        $this->assertIsObject($pivotRequest->input('data'));
        $this->assertEquals('Test', $pivotRequest->input('data')->title);
    }

    public function testCreateRequestWithAttributes(): void
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest('GET', '/test')
            ->withAttribute('user_id', 123)
            ->withAttribute('role', 'admin');

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals(123, $pivotRequest->getAttribute('user_id'));
        $this->assertEquals('admin', $pivotRequest->getAttribute('role'));
        $this->assertNull($pivotRequest->getAttribute('nonexistent'));
    }

    public function testCreateRequestWithUploadedFiles(): void
    {
        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $uploadedFile->method('getClientFilename')->willReturn('test.txt');
        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');
        $uploadedFile->method('getSize')->willReturn(1024);
        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);

        $stream = $this->streamFactory->createStream('test content');
        $uploadedFile->method('getStream')->willReturn($stream);

        $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/upload')
            ->withUploadedFiles(['file' => $uploadedFile]);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertTrue($pivotRequest->hasFile('file'));

        $file = $pivotRequest->file('file');
        $this->assertIsArray($file);
        $this->assertEquals('test.txt', $file['name']);
        $this->assertEquals('text/plain', $file['type']);
        $this->assertEquals(1024, $file['size']);
        $this->assertEquals(UPLOAD_ERR_OK, $file['error']);
    }

    public function testCreateRequestHandlesEmptyBody(): void
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest('GET', '/test');

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        // For GET requests, body should be empty
        $this->assertNull($pivotRequest->input('nonexistent'));
    }

    public function testCreateRequestWithInvalidJson(): void
    {
        $body = $this->streamFactory->createStream('{"invalid":json}');
        $psrRequest = $this->serverRequestFactory->createServerRequest('POST', '/test')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        // Invalid JSON should not cause errors, just no data
        $this->assertNull($pivotRequest->input('invalid'));
    }

    public function testCreateRequestWithComplexData(): void
    {
        $body = $this->streamFactory->createStream('{"user":{"name":"Alice","settings":{"theme":"dark","notifications":true}}}');
        $psrRequest = $this->serverRequestFactory->createServerRequest('PUT', '/profile')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-ID', 'abc123')
            ->withQueryParams(['version' => '2'])
            ->withAttribute('authenticated', true)
            ->withBody($body);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);
        $this->assertEquals('PUT', $pivotRequest->getMethod());
        $this->assertEquals('/profile', $pivotRequest->getPath());

        // Check query params
        $this->assertEquals('2', $pivotRequest->get('version'));

        // Check body data
        $user = $pivotRequest->input('user');
        $this->assertIsObject($user);
        $this->assertEquals('Alice', $user->name);
        $this->assertIsObject($user->settings);
        $this->assertEquals('dark', $user->settings->theme);
        $this->assertTrue($user->settings->notifications);

        // Check attributes
        $this->assertTrue($pivotRequest->getAttribute('authenticated'));

        // Check headers
        $headers = $pivotRequest->getHeadersObject();
        $this->assertEquals('application/json', $headers->contentType());
    }

    public function testFactoryCreateMethod(): void
    {
        $factory1 = RequestFactory::create();
        $factory2 = RequestFactory::create();

        $this->assertInstanceOf(RequestFactory::class, $factory1);
        $this->assertInstanceOf(RequestFactory::class, $factory2);
        $this->assertNotSame($factory1, $factory2); // Should create new instances
    }

    public function testCreateRequestWithMultipleHeaderValues(): void
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest('GET', '/test')
            ->withHeader('Accept', ['application/json', 'text/html']);

        $pivotRequest = $this->factory->createFromPsr7($psrRequest);

        $this->assertInstanceOf(PivotRequest::class, $pivotRequest);

        $headers = $pivotRequest->getHeadersObject();
        $this->assertEquals('application/json, text/html', $headers->accept());
    }
}
