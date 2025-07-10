<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Server;

use PivotPHP\Core\Core\Application;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use React\Promise\Promise;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ReactPHP Server with compatibility workarounds for PSR-7 conflicts
 */
final class ReactServerCompat
{
    private Application $application;
    private LoopInterface $loop;
    private LoggerInterface $logger;
    private array $config;
    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private bool $running = false;

    public function __construct(
        Application $application,
        LoopInterface $loop,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->application = $application;
        $this->loop = $loop;
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'host' => '0.0.0.0',
            'port' => 8080,
            'workers' => 1,
            'middleware' => [],
        ], $config);
    }

    public function start(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Server is already running');
        }

        $this->setupSignalHandlers();

        $host = $this->config['host'];
        $port = $this->config['port'];
        $address = "{$host}:{$port}";

        $this->logger->info('Starting ReactPHP server', [
            'address' => $address,
            'workers' => $this->config['workers'],
            'pid' => getmypid(),
        ]);

        // Create HTTP server with a simple handler that avoids PSR-7 conflicts
        $this->server = new HttpServer(
            $this->loop,
            function ($request) {
                return $this->handleRequestCompat($request);
            }
        );

        $this->socket = new SocketServer($address, [], $this->loop);
        $this->server->listen($this->socket);

        $this->running = true;
        $this->logger->info('ReactPHP server started', ['address' => $address]);

        // Emit server started event
        $this->emitEvent('server.started', ['address' => $address]);

        // Start the event loop
        $this->loop->run();
    }

    /**
     * Handle request with compatibility workaround
     */
    private function handleRequestCompat(\React\Http\Message\ServerRequest $reactRequest): Promise
    {
        return new Promise(function ($resolve, $reject) use ($reactRequest) {
            try {
                // Extract basic request data from ReactPHP request
                $requestData = $this->extractRequestData($reactRequest);

                // Create PivotPHP Request from extracted data
                $pivotRequest = $this->createPivotRequest($requestData);

                // Process the request through routing and get response
                $reactResponse = $this->processRoute($pivotRequest, $requestData);

                $resolve($reactResponse);
            } catch (\Exception $e) {
                $errorResponse = $this->createErrorResponse($e);
                $resolve($errorResponse);
            }
        });
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->logger->info('Stopping ReactPHP server...');
        $this->emitEvent('server.stopping');

        if ($this->socket !== null) {
            $this->socket->close();
        }

        $this->loop->stop();
        $this->running = false;

        $this->logger->info('ReactPHP server stopped');
    }

    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function ($signal) {
            $this->logger->info('Received signal', ['signal' => $signal]);
            $this->stop();
            exit(0);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        // Enable async signals
        pcntl_async_signals(true);
    }

    private function emitEvent(string $event, array $data = []): void
    {
        // Log event instead of using event dispatcher to avoid PSR-14 issues
        $this->logger->info($event, $data);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Extract basic request data from ReactPHP ServerRequest
     */
    private function extractRequestData(\React\Http\Message\ServerRequest $reactRequest): array
    {
        $uri = (string) $reactRequest->getUri();
        $parsedUrl = parse_url($uri);

        return [
            'method' => $reactRequest->getMethod(),
            'uri' => $uri,
            'path' => $parsedUrl['path'] ?? '/',
            'query' => $parsedUrl['query'] ?? '',
            'headers' => $reactRequest->getHeaders(),
            'body' => (string) $reactRequest->getBody(),
        ];
    }

    /**
     * Create PivotPHP Request from extracted request data
     */
    private function createPivotRequest(array $requestData): \PivotPHP\Core\Http\Request
    {
        // Create base PivotPHP Request
        $pivotRequest = new \PivotPHP\Core\Http\Request(
            $requestData['method'],
            $requestData['path'],
            $requestData['path']
        );

        // Apply headers
        $pivotRequest = $this->applyHeaders($pivotRequest, $requestData['headers']);

        // Apply query parameters
        $pivotRequest = $this->applyQueryParameters($pivotRequest, $requestData['query']);

        // Apply body data
        $pivotRequest = $this->applyBodyData($pivotRequest, $requestData['body']);

        return $pivotRequest;
    }

    /**
     * Apply headers to PivotPHP Request
     */
    private function applyHeaders(
        \PivotPHP\Core\Http\Request $pivotRequest,
        array $headers
    ): \PivotPHP\Core\Http\Request {
        foreach ($headers as $name => $values) {
            $headerValue = is_array($values) ? implode(', ', $values) : $values;
            $pivotRequest = $pivotRequest->withHeader($name, $headerValue);
        }

        return $pivotRequest;
    }

    /**
     * Apply query parameters to PivotPHP Request
     */
    private function applyQueryParameters(
        \PivotPHP\Core\Http\Request $pivotRequest,
        string $query
    ): \PivotPHP\Core\Http\Request {
        if ($query !== '') {
            parse_str($query, $queryParams);
            return $pivotRequest->withQueryParams($queryParams);
        }

        return $pivotRequest;
    }

    /**
     * Apply body data to PivotPHP Request based on content type
     */
    private function applyBodyData(\PivotPHP\Core\Http\Request $pivotRequest, string $body): \PivotPHP\Core\Http\Request
    {
        if ($body === '') {
            return $pivotRequest;
        }

        $contentType = $pivotRequest->getHeaderLine('Content-Type');

        // Parse body based on content type
        if (str_contains($contentType, 'application/json')) {
            $pivotRequest = $this->parseJsonBody($pivotRequest, $body);
        } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $pivotRequest = $this->parseFormBody($pivotRequest, $body);
        }

        // Set body stream using StreamFactory for consistency
        $streamFactory = new \PivotPHP\Core\Http\Psr7\Factory\StreamFactory();
        $stream = $streamFactory->createStream($body);
        $pivotRequest = $pivotRequest->withBody($stream);

        return $pivotRequest;
    }

    /**
     * Parse JSON body and apply to request
     */
    private function parseJsonBody(\PivotPHP\Core\Http\Request $pivotRequest, string $body): \PivotPHP\Core\Http\Request
    {
        $parsedBody = json_decode($body, true);
        if (is_array($parsedBody)) {
            return $pivotRequest->withParsedBody($parsedBody);
        }

        return $pivotRequest;
    }

    /**
     * Parse form-encoded body and apply to request
     */
    private function parseFormBody(\PivotPHP\Core\Http\Request $pivotRequest, string $body): \PivotPHP\Core\Http\Request
    {
        parse_str($body, $parsedBody);
        return $pivotRequest->withParsedBody($parsedBody);
    }

    /**
     * Process route and create ReactPHP response
     */
    private function processRoute(
        \PivotPHP\Core\Http\Request $pivotRequest,
        array $requestData
    ): \React\Http\Message\Response {
        $route = \PivotPHP\Core\Routing\Router::identify($requestData['method'], $requestData['path']);

        if ($route !== null) {
            return $this->executeRoute($route, $pivotRequest);
        }

        return $this->createNotFoundResponse();
    }

    /**
     * Execute route handler and create response
     */
    private function executeRoute(array $route, \PivotPHP\Core\Http\Request $pivotRequest): \React\Http\Message\Response
    {
        $handler = $route['handler'];
        $pivotResponse = new \PivotPHP\Core\Http\Response();

        // Capture output from route handler
        ob_start();
        $handler($pivotRequest, $pivotResponse);
        $output = ob_get_clean();

        // Create ReactPHP response
        return new \React\Http\Message\Response(
            200,
            ['Content-Type' => 'application/json'],
            $output !== false ? $output : ''
        );
    }

    /**
     * Create 404 Not Found response
     */
    private function createNotFoundResponse(): \React\Http\Message\Response
    {
        $notFoundBody = json_encode(['error' => 'Not Found']);

        return new \React\Http\Message\Response(
            404,
            ['Content-Type' => 'application/json'],
            $notFoundBody !== false ? $notFoundBody : '{"error":"Not Found"}'
        );
    }

    /**
     * Create error response from exception
     */
    private function createErrorResponse(\Exception $e): \React\Http\Message\Response
    {
        $this->logger->error('Error handling request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $errorBody = json_encode(['error' => 'Internal Server Error']);

        return new \React\Http\Message\Response(
            500,
            ['Content-Type' => 'application/json'],
            $errorBody !== false ? $errorBody : '{"error":"Internal Server Error"}'
        );
    }
}
