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

    public function __construct(
        ?ServerRequestFactory $requestFactory = null,
        ?StreamFactory $streamFactory = null
    ) {
        $this->requestFactory = $requestFactory ?? new ServerRequestFactory();
        $this->streamFactory = $streamFactory ?? new StreamFactory();
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

        // Copy protocol version
        $request = $request->withProtocolVersion($reactRequest->getProtocolVersion());

        // Copy request target
        $request = $request->withRequestTarget($reactRequest->getRequestTarget());

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
        } else {
            // If no parsed body, try to parse based on content-type
            $contentType = $reactRequest->getHeaderLine('Content-Type');

            // Ensure stream is at the beginning before reading
            $bodyStream = $reactRequest->getBody();
            $bodyStream->rewind();
            $bodyContents = (string) $bodyStream;

            if ($bodyContents !== '') {
                if (stripos($contentType, 'application/json') !== false) {
                    $decoded = json_decode($bodyContents, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $request = $request->withParsedBody($decoded);
                    }
                } elseif (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                    parse_str($bodyContents, $formData);
                    $request = $request->withParsedBody($formData);
                }
            }
        }

        // Copy cookie params
        $request = $request->withCookieParams($reactRequest->getCookieParams());

        // Copy uploaded files
        $uploadedFiles = $reactRequest->getUploadedFiles();
        if ($uploadedFiles !== []) {
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
            'HTTP_HOST' => $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : ''),
            'SERVER_NAME' => $uri->getHost(),
            'SERVER_PORT' => $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80),
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
