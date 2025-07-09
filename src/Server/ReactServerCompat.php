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
    private function handleRequestCompat($reactRequest): Promise
    {
        return new Promise(function ($resolve, $reject) use ($reactRequest) {
            try {
                // Extract request data without using PSR-7 interfaces
                $method = $reactRequest->getMethod();
                $uri = (string) $reactRequest->getUri();
                $headers = $reactRequest->getHeaders();
                $body = (string) $reactRequest->getBody();

                // Parse URI
                $parsedUrl = parse_url($uri);
                $path = $parsedUrl['path'] ?? '/';
                $query = $parsedUrl['query'] ?? '';

                // Create PivotPHP Request manually
                $pivotRequest = new \PivotPHP\Core\Http\Request($method, $path, $path);

                // Set headers
                foreach ($headers as $name => $values) {
                    $pivotRequest->headers->set($name, is_array($values) ? implode(', ', $values) : $values);
                }

                // Parse query params
                if ($query) {
                    parse_str($query, $queryParams);
                    foreach ($queryParams as $key => $value) {
                        $pivotRequest->query->$key = $value;
                    }
                }

                // Parse body
                if ($body) {
                    $contentType = $pivotRequest->headers->get('content-type', '');

                    if (str_contains($contentType, 'application/json')) {
                        $parsedBody = json_decode($body, true);
                        if (is_array($parsedBody)) {
                            foreach ($parsedBody as $key => $value) {
                                $pivotRequest->body->$key = $value;
                            }
                        }
                    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                        parse_str($body, $parsedBody);
                        foreach ($parsedBody as $key => $value) {
                            $pivotRequest->body->$key = $value;
                        }
                    }
                }

                // Create PivotPHP Response
                $pivotResponse = new \PivotPHP\Core\Http\Response();

                // Find and execute route
                $route = \PivotPHP\Core\Routing\Router::identify($method, $path);

                if ($route) {
                    // Execute route handler
                    $handler = $route['handler'];

                    // Capture output
                    ob_start();
                    $handler($pivotRequest, $pivotResponse);
                    $output = ob_get_clean();

                    // Create React response manually
                    $reactResponse = new \React\Http\Message\Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        $output
                    );

                    $resolve($reactResponse);
                } else {
                    // 404 Not Found
                    $reactResponse = new \React\Http\Message\Response(
                        404,
                        ['Content-Type' => 'application/json'],
                        json_encode(['error' => 'Not Found'])
                    );

                    $resolve($reactResponse);
                }
            } catch (\Exception $e) {
                $this->logger->error('Error handling request', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $reactResponse = new \React\Http\Message\Response(
                    500,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Internal Server Error'])
                );

                $resolve($reactResponse);
            }
        });
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->logger->info('Stopping ReactPHP server...');
        $this->emitEvent('server.stopping');

        if ($this->socket) {
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
}
