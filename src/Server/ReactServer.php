<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Server;

use PivotPHP\Core\Core\Application;
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
                
                $psrRequest = $this->requestBridge->convertFromReact($request);
                
                $psrResponse = $this->application->handle($psrRequest);
                
                $reactResponse = $this->responseBridge->convertToReact($psrResponse);
                
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
                $this->logger->error('Request handling failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                $resolve(new ReactResponse(
                    500,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'error' => 'Internal Server Error',
                        'message' => $this->config['debug'] ? $e->getMessage() : 'An error occurred',
                    ])
                ));
            }
        });
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