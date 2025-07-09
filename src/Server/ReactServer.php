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
    private SocketServer $socketServer;
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

        $this->socketServer->close();
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
                // The application now returns a PSR-7 ResponseInterface
                $psrResponse = $this->application->handle($psrRequest);

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
                $errorResponse = $errorHandler->handle($e);
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
        $resolve(new ReactResponse(
            500,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => 'Internal Server Error',
                'message' => $this->config['debug'] ? $e->getMessage() : 'An error occurred',
                'error_id' => uniqid('err_', true),
            ])
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
}
