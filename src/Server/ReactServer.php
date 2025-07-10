<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Server;

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response as PivotResponse;
use PivotPHP\ReactPHP\Bridge\RequestBridge;
use PivotPHP\ReactPHP\Bridge\ResponseBridge;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as ReactResponse;
use React\Promise\Promise;
use React\Socket\SocketServer;
use Throwable;

final class ReactServer
{
    private HttpServer $httpServer;
    private ?SocketServer $socketServer = null;
    private LoopInterface $loop;
    private LoggerInterface $logger;
    private RequestBridge $requestBridge;
    private ResponseBridge $responseBridge;
    private array $config;

    public function __construct(
        private Application $application,
        ?LoopInterface $loop = null,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge($this->getDefaultConfig(), $config);

        $this->requestBridge = new RequestBridge();
        $this->responseBridge = new ResponseBridge();

        $this->initializeHttpServer();
    }

    public function listen(string $address = '0.0.0.0:8080'): void
    {
        $this->socketServer = new SocketServer($address, [], $this->loop);
        $this->httpServer->listen($this->socketServer);

        $this->logger->info('ReactPHP server started', [
            'address' => $address,
            'pid' => getmypid(),
            'memory' => memory_get_usage(true),
        ]);

        $this->registerSignalHandlers();
        $this->loop->run();
    }

    public function stop(): void
    {
        $this->logger->info('Stopping ReactPHP server...');

        if ($this->socketServer !== null) {
            $this->socketServer->close();
            $this->socketServer = null;
        }

        $this->loop->stop();

        $this->logger->info('ReactPHP server stopped');
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    private function initializeHttpServer(): void
    {
        $middleware = [];

        if ($this->config['streaming']) {
            $middleware[] = new \React\Http\Middleware\StreamingRequestMiddleware();
        }

        if ($this->config['request_body_buffer_size'] !== null) {
            $middleware[] = new \React\Http\Middleware\RequestBodyBufferMiddleware(
                $this->config['request_body_buffer_size']
            );
        }

        if ($this->config['request_body_size_limit'] !== null) {
            $middleware[] = new \React\Http\Middleware\LimitConcurrentRequestsMiddleware(
                $this->config['max_concurrent_requests']
            );
        }

        $middleware[] = [$this, 'handleRequest'];

        $this->httpServer = new HttpServer($this->loop, ...$middleware);
    }

    public function handleRequest(ServerRequestInterface $request): Promise
    {
        return new Promise(function ($resolve, $reject) use ($request) {
            try {
                $startTime = microtime(true);

                // Convert ReactPHP request to PSR-7 ServerRequest
                $psrRequest = $this->requestBridge->convertFromReact($request);

                // Handle request through PivotPHP Application
                // Convert PSR-7 to PivotPHP Request if needed (concurrency-safe)
                if (!($psrRequest instanceof \PivotPHP\Core\Http\Request)) {
                    // Create PivotPHP Request without modifying global state
                    // This prevents race conditions in concurrent request handling
                    $pivotRequest = $this->createPivotRequestFromPsr7($psrRequest);

                    $psrResponse = $this->application->handle($pivotRequest);
                } else {
                    $psrResponse = $this->application->handle($psrRequest);
                }

                // Convert PSR-7 Response to ReactPHP Response
                // Use streaming if enabled and response indicates streaming
                if ($this->config['streaming'] && $this->isStreamingResponse($psrResponse)) {
                    $reactResponse = $this->responseBridge->convertToReactStream($psrResponse);
                } else {
                    $reactResponse = $this->responseBridge->convertToReact($psrResponse);
                }

                $duration = (microtime(true) - $startTime) * 1000;

                $this->logger->info('Request handled', [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'status' => $psrResponse->getStatusCode(),
                    'duration_ms' => round($duration, 2),
                    'memory' => memory_get_usage(true),
                ]);

                $resolve($reactResponse);
            } catch (Throwable $e) {
                $this->handleError($e, $resolve);
            }
        });
    }

    private function handleError(Throwable $e, callable $resolve): void
    {
        $this->logger->error('Request handling failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Use PivotPHP's error handling if available
        if ($this->application->has('error.handler')) {
            try {
                $errorHandler = $this->application->make('error.handler');
                if (is_object($errorHandler) && method_exists($errorHandler, 'handle')) {
                    $errorResponse = $errorHandler->handle($e);
                } else {
                    throw new \RuntimeException('Invalid error handler');
                }
                $reactResponse = $this->responseBridge->convertToReact($errorResponse);
                $resolve($reactResponse);
                return;
            } catch (Throwable $handlerError) {
                $this->logger->error('Error handler failed', [
                    'error' => $handlerError->getMessage(),
                ]);
            }
        }

        // Fallback error response
        $errorBody = json_encode([
            'error' => 'Internal Server Error',
            'message' => $this->config['debug'] ? $e->getMessage() : 'An error occurred',
            'error_id' => uniqid('err_', true),
        ]);
        $resolve(new ReactResponse(
            500,
            ['Content-Type' => 'application/json'],
            $errorBody !== false ? $errorBody : '{"error":"Internal Server Error"}'
        ));
    }

    private function isStreamingResponse(\Psr\Http\Message\ResponseInterface $response): bool
    {
        // Check if response should be streamed based on headers or other indicators
        $contentType = $response->getHeaderLine('Content-Type');
        $transferEncoding = $response->getHeaderLine('Transfer-Encoding');

        // Stream if chunked transfer encoding is used
        if ($transferEncoding === 'chunked') {
            return true;
        }

        // Stream for certain content types
        $streamableTypes = [
            'text/event-stream',
            'application/octet-stream',
            'video/',
            'audio/',
        ];

        foreach ($streamableTypes as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }

        // Check for custom streaming header
        return $response->hasHeader('X-Stream-Response');
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal) {
            $this->logger->info('Received signal', ['signal' => $signal]);
            $this->stop();
        };

        $this->loop->addSignal(SIGTERM, $handler);
        $this->loop->addSignal(SIGINT, $handler);
    }

    private function getDefaultConfig(): array
    {
        return [
            'debug' => false,
            'streaming' => false,
            'max_concurrent_requests' => 100,
            'request_body_size_limit' => 67108864, // 64MB
            'request_body_buffer_size' => 8192, // 8KB
        ];
    }

    /**
     * Create a PivotPHP Request from PSR-7 ServerRequest using direct factory approach
     *
     * This method ensures complete concurrency safety by avoiding any global state
     * modification and using reflection to directly set private properties.
     */
    private function createPivotRequestFromPsr7(\Psr\Http\Message\ServerRequestInterface $psrRequest): \PivotPHP\Core\Http\Request
    {
        $uri = $psrRequest->getUri();
        $method = $psrRequest->getMethod();
        $path = $uri->getPath();

        try {
            // Create a minimal PivotPHP Request instance using reflection
            $reflection = new \ReflectionClass(\PivotPHP\Core\Http\Request::class);
            $pivotRequest = $reflection->newInstanceWithoutConstructor();

            // Set basic properties directly (using actual PivotPHP property names)
            $this->setRequestProperty($pivotRequest, 'method', strtoupper($method));
            $this->setRequestProperty($pivotRequest, 'path', $path);
            $this->setRequestProperty($pivotRequest, 'pathCallable', $path);

            // Set headers
            $this->setRequestHeaders($pivotRequest, $psrRequest->getHeaders());

            // Set query parameters and other objects
            $this->setRequestProperty($pivotRequest, 'params', new \stdClass());
            $queryParams = $psrRequest->getQueryParams();
            if (count($queryParams) > 0) {
                $this->setRequestProperty($pivotRequest, 'query', (object) $queryParams);
            } else {
                $this->setRequestProperty($pivotRequest, 'query', new \stdClass());
            }

            // Set request body
            $bodyContent = (string) $psrRequest->getBody();
            if ($method !== 'GET' && $bodyContent !== '') {
                $this->setRequestBody($pivotRequest, $bodyContent, $psrRequest->getHeaderLine('Content-Type'));
            } else {
                $this->setRequestProperty($pivotRequest, 'body', new \stdClass());
            }

            // Set uploaded files and attributes
            $uploadedFiles = $psrRequest->getUploadedFiles();
            if (count($uploadedFiles) > 0) {
                $this->setRequestProperty($pivotRequest, 'files', $this->convertUploadedFiles($uploadedFiles));
            } else {
                $this->setRequestProperty($pivotRequest, 'files', []);
            }

            // Initialize attributes array
            $this->setRequestProperty($pivotRequest, 'attributes', []);

            // Set attributes from PSR-7 request
            foreach ($psrRequest->getAttributes() as $name => $value) {
                $pivotRequest->setAttribute($name, $value);
            }

            return $pivotRequest;
        } catch (\ReflectionException $e) {
            throw new \RuntimeException('Failed to create PivotPHP Request: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Helper method to set private properties on PivotPHP Request using reflection
     */
    private function setRequestProperty(\PivotPHP\Core\Http\Request $request, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($request);
        if ($reflection->hasProperty($property)) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($request, $value);
        }
    }

    /**
     * Helper method to set headers on PivotPHP Request
     */
    private function setRequestHeaders(\PivotPHP\Core\Http\Request $request, array $headers): void
    {
        // Create a HeaderRequest object with the converted headers
        $headerRequest = new \PivotPHP\Core\Http\HeaderRequest();

        // Use reflection to set the headers directly on the HeaderRequest object
        $headerReflection = new \ReflectionClass($headerRequest);
        if ($headerReflection->hasProperty('headers')) {
            $headersProperty = $headerReflection->getProperty('headers');
            $headersProperty->setAccessible(true);

            // Convert headers to PivotPHP format (camelCase keys)
            $pivotHeaders = [];
            foreach ($headers as $name => $values) {
                $camelCaseName = $this->convertHeaderToCamelCase($name);
                $pivotHeaders[$camelCaseName] = is_array($values) ? implode(', ', $values) : $values;
            }

            $headersProperty->setValue($headerRequest, $pivotHeaders);
        }

        // Set the HeaderRequest object on the main request
        $this->setRequestProperty($request, 'headers', $headerRequest);
    }

    /**
     * Convert header name to camelCase format that PivotPHP expects
     */
    private function convertHeaderToCamelCase(string $headerName): string
    {
        // Convert "Content-Type" to "contentType", "Authorization" to "authorization", etc.
        $parts = explode('-', strtolower($headerName));
        $camelCase = array_shift($parts);

        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }

        return $camelCase;
    }

    /**
     * Convert PSR-7 uploaded files to PHP $_FILES format
     */
    private function convertUploadedFiles(array $uploadedFiles): array
    {
        $files = [];

        foreach ($uploadedFiles as $name => $file) {
            if ($file instanceof \Psr\Http\Message\UploadedFileInterface) {
                $files[$name] = [
                    'name' => $file->getClientFilename(),
                    'type' => $file->getClientMediaType(),
                    'size' => $file->getSize(),
                    'tmp_name' => $file->getStream()->getMetadata('uri') ?? '',
                    'error' => $file->getError(),
                ];
            }
        }

        return $files;
    }


    /**
     * Set request body on PivotPHP Request object using reflection
     * This is needed because PivotPHP's parseBody() method doesn't work with php://input in ReactPHP
     */
    private function setRequestBody(\PivotPHP\Core\Http\Request $pivotRequest, string $bodyContent, string $contentType): void
    {
        try {
            $reflection = new \ReflectionClass($pivotRequest);
            $bodyProperty = $reflection->getProperty('body');
            $bodyProperty->setAccessible(true);

            // Parse body content based on content type
            if (stripos($contentType, 'application/json') !== false) {
                $decoded = json_decode($bodyContent);
                if ($decoded instanceof \stdClass) {
                    $bodyProperty->setValue($pivotRequest, $decoded);
                } elseif (is_array($decoded)) {
                    // Convert array to stdClass for PivotPHP compatibility
                    $bodyProperty->setValue($pivotRequest, (object) $decoded);
                } else {
                    // Invalid JSON, set empty object
                    $bodyProperty->setValue($pivotRequest, new \stdClass());
                }
            } elseif (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                parse_str($bodyContent, $parsed);
                $bodyProperty->setValue($pivotRequest, (object) $parsed);
            } else {
                // For other content types, try to parse as JSON first, then as form data
                $decoded = json_decode($bodyContent);
                if ($decoded instanceof \stdClass) {
                    $bodyProperty->setValue($pivotRequest, $decoded);
                } else {
                    // Try form data
                    parse_str($bodyContent, $parsed);
                    $bodyProperty->setValue($pivotRequest, (object) $parsed);
                }
            }
        } catch (\ReflectionException $e) {
            // If reflection fails, just log it and continue
            $this->logger->warning('Could not set request body', ['error' => $e->getMessage()]);
        }
    }
}
