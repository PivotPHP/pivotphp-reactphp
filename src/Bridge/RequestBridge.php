<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Bridge;

use PivotPHP\Core\Http\Psr7\Factory\ServerRequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\StreamFactory;
use PivotPHP\ReactPHP\Adapter\Psr7CompatibilityAdapter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use React\Http\Message\ServerRequest as ReactServerRequest;

final class RequestBridge
{
    private ServerRequestFactory $requestFactory;
    private StreamFactory $streamFactory;

    public function __construct(?ServerRequestFactory $requestFactory = null, ?StreamFactory $streamFactory = null)
    {
        $this->requestFactory = $requestFactory ?? new ServerRequestFactory();
        $this->streamFactory = $streamFactory ?? new StreamFactory();
    }

    public function convertFromReact(ServerRequestInterface $reactRequest): \PivotPHP\Core\Http\Request
    {
        // Save current global state
        $originalServer = $_SERVER ?? [];
        $originalGet = $_GET ?? [];
        $originalPost = $_POST ?? [];

        try {
            // Extract data from React request
            $method = $reactRequest->getMethod();
            $uri = $reactRequest->getUri();
            $path = $uri->getPath();

            // Prepare $_SERVER for headers
            $_SERVER = [];
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REQUEST_URI'] = $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : '');
            $_SERVER['QUERY_STRING'] = $uri->getQuery() ?? '';

            // Convert headers to $_SERVER format
            foreach ($reactRequest->getHeaders() as $name => $values) {
                $value = is_array($values) ? implode(', ', $values) : $values;
                $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
                $_SERVER[$headerName] = $value;
            }

            // Handle special headers
            if ($reactRequest->hasHeader('Content-Type')) {
                $_SERVER['CONTENT_TYPE'] = $reactRequest->getHeaderLine('Content-Type');
            }
            if ($reactRequest->hasHeader('Content-Length')) {
                $_SERVER['CONTENT_LENGTH'] = $reactRequest->getHeaderLine('Content-Length');
            }

            // Set query parameters
            $_GET = $reactRequest->getQueryParams();

            // Set body parameters
            $_POST = [];
            $parsedBody = $reactRequest->getParsedBody();
            if (is_array($parsedBody)) {
                $_POST = $parsedBody;
            } elseif (is_object($parsedBody)) {
                $_POST = (array) $parsedBody;
            } else {
                // Handle raw body content
                $body = (string) $reactRequest->getBody();
                if ($body) {
                    $contentType = $reactRequest->getHeaderLine('content-type');

                    if (str_contains($contentType, 'application/json')) {
                        $decoded = json_decode($body, true);
                        if (is_array($decoded)) {
                            $_POST = $decoded;
                        }
                    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                        parse_str($body, $_POST);
                    }
                }
            }

            // Create PivotPHP Request (will read from globals)
            $pivotRequest = new \PivotPHP\Core\Http\Request($method, $path, $path);

            return $pivotRequest;
        } finally {
            // Restore original global state
            $_SERVER = $originalServer;
            $_GET = $originalGet;
            $_POST = $originalPost;
        }
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
