<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Bridge;

use PivotPHP\Core\Http\Psr7\Factory\ServerRequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\StreamFactory;
use PivotPHP\Core\Http\Psr7\Factory\UriFactory;
use PivotPHP\Core\Http\Psr7\Factory\UploadedFileFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use React\Http\Message\ServerRequest as ReactServerRequest;

final class RequestBridge
{
    private ServerRequestFactory $requestFactory;
    private StreamFactory $streamFactory;
    private UriFactory $uriFactory;
    private UploadedFileFactory $uploadedFileFactory;

    public function __construct(
        ?ServerRequestFactory $requestFactory = null,
        ?StreamFactory $streamFactory = null,
        ?UriFactory $uriFactory = null,
        ?UploadedFileFactory $uploadedFileFactory = null
    ) {
        $this->requestFactory = $requestFactory ?? new ServerRequestFactory();
        $this->streamFactory = $streamFactory ?? new StreamFactory();
        $this->uriFactory = $uriFactory ?? new UriFactory();
        $this->uploadedFileFactory = $uploadedFileFactory ?? new UploadedFileFactory();
    }

    public function convertFromReact(ServerRequestInterface $reactRequest): ServerRequestInterface
    {
        // Since PivotPHP Core 1.1.0 Request implements ServerRequestInterface,
        // we can create a proper PSR-7 ServerRequest and return it directly
        
        $uri = $reactRequest->getUri();
        $serverParams = $this->prepareServerParams($reactRequest);
        
        // Create PSR-7 ServerRequest using PivotPHP's factory
        $request = $this->requestFactory->createServerRequest(
            $reactRequest->getMethod(),
            $uri,
            $serverParams
        );
        
        // Copy headers
        foreach ($reactRequest->getHeaders() as $name => $values) {
            $request = $request->withHeader($name, $values);
        }
        
        // Copy body
        $body = $this->convertBody($reactRequest->getBody());
        $request = $request->withBody($body);
        
        // Copy query params
        $request = $request->withQueryParams($reactRequest->getQueryParams());
        
        // Copy parsed body
        $parsedBody = $reactRequest->getParsedBody();
        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }
        
        // Copy cookie params
        $request = $request->withCookieParams($reactRequest->getCookieParams());
        
        // Copy uploaded files
        $uploadedFiles = $reactRequest->getUploadedFiles();
        if (!empty($uploadedFiles)) {
            $request = $request->withUploadedFiles($this->convertUploadedFiles($uploadedFiles));
        }
        
        // Copy attributes
        foreach ($reactRequest->getAttributes() as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        
        return $request;
    }

    private function prepareServerParams(ServerRequestInterface $request): array
    {
        $uri = $request->getUri();
        $server = [
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $request->getRequestTarget(),
            'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
            'HTTP_HOST' => $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : ''),
            'SERVER_NAME' => $uri->getHost(),
            'SERVER_PORT' => $uri->getPort() ?: ($uri->getScheme() === 'https' ? 443 : 80),
            'REQUEST_SCHEME' => $uri->getScheme(),
            'HTTPS' => $uri->getScheme() === 'https' ? 'on' : 'off',
            'QUERY_STRING' => $uri->getQuery(),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];

        foreach ($request->getHeaders() as $name => $values) {
            $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$headerName] = implode(', ', $values);
        }

        if ($request->hasHeader('Content-Type')) {
            $server['CONTENT_TYPE'] = $request->getHeaderLine('Content-Type');
        }

        if ($request->hasHeader('Content-Length')) {
            $server['CONTENT_LENGTH'] = $request->getHeaderLine('Content-Length');
        }

        if ($request instanceof ReactServerRequest) {
            $serverParams = $request->getServerParams();
            if (isset($serverParams['REMOTE_ADDR'])) {
                $server['REMOTE_ADDR'] = $serverParams['REMOTE_ADDR'];
            }
            if (isset($serverParams['REMOTE_PORT'])) {
                $server['REMOTE_PORT'] = $serverParams['REMOTE_PORT'];
            }
        }

        return $server;
    }

    private function convertBody(StreamInterface $reactBody): StreamInterface
    {
        $content = (string) $reactBody;
        $stream = $this->streamFactory->createStream($content);
        $stream->rewind();
        return $stream;
    }

    private function convertUploadedFiles(array $uploadedFiles): array
    {
        $converted = [];

        foreach ($uploadedFiles as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                $converted[$key] = $file;
            } elseif (is_array($file)) {
                $converted[$key] = $this->convertUploadedFiles($file);
            }
        }

        return $converted;
    }
}
