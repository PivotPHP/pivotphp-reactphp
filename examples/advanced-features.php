<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\Core\Performance\HighPerformanceMode;
use PivotPHP\ReactPHP\Providers\ReactPHPServiceProvider;
use PivotPHP\ReactPHP\Server\ReactServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Stream\ThroughStream;

$app = new Application(__DIR__);

// Enable high-performance mode from PivotPHP 1.1.0
if (class_exists(HighPerformanceMode::class)) {
    HighPerformanceMode::enable(HighPerformanceMode::PROFILE_HIGH);
}

$app->register(ReactPHPServiceProvider::class);

$router = $app->make(Router::class);
$loop = $app->make(LoopInterface::class);

// Demonstrate new PivotPHP 1.1.0 middleware features
$app->use('rate-limiter'); // Using middleware alias from 1.1.0
$app->use('circuit-breaker'); // Circuit breaker for resilience

// Example: Server-Sent Events (SSE) endpoint
$router->get('/sse/events', function (ServerRequestInterface $request): ResponseInterface {
    $stream = new ThroughStream();
    
    // Send SSE headers
    $response = new \React\Http\Message\Response(
        200,
        [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Stream-Response' => 'true', // Custom header for streaming
        ],
        $stream
    );
    
    // Send initial event
    $stream->write("event: connected\ndata: " . json_encode(['time' => time()]) . "\n\n");
    
    // Send periodic updates
    $timer = $app->make(LoopInterface::class)->addPeriodicTimer(2.0, function () use ($stream) {
        $data = [
            'time' => time(),
            'memory' => memory_get_usage(true),
            'random' => random_int(1, 100),
        ];
        $stream->write("event: update\ndata: " . json_encode($data) . "\n\n");
    });
    
    // Clean up timer when connection closes
    $stream->on('close', function () use ($timer, $app) {
        $app->make(LoopInterface::class)->cancelTimer($timer);
    });
    
    return $response;
});

// Example: Streaming large file download
$router->get('/stream/download', function (): ResponseInterface {
    $filePath = __DIR__ . '/../README.md';
    $fileSize = filesize($filePath);
    $stream = new ThroughStream();
    
    // Create response with appropriate headers
    $response = new \React\Http\Message\Response(
        200,
        [
            'Content-Type' => 'text/markdown',
            'Content-Length' => (string) $fileSize,
            'Content-Disposition' => 'attachment; filename="README.md"',
            'X-Stream-Response' => 'true',
        ],
        $stream
    );
    
    // Stream file content in chunks
    $handle = fopen($filePath, 'rb');
    if ($handle) {
        $app = Application::getInstance();
        $loop = $app->make(LoopInterface::class);
        
        $readChunk = function () use ($handle, $stream, &$readChunk, $loop) {
            $chunk = fread($handle, 8192); // 8KB chunks
            if ($chunk !== false && strlen($chunk) > 0) {
                $stream->write($chunk);
                $loop->futureTick($readChunk);
            } else {
                fclose($handle);
                $stream->end();
            }
        };
        
        $loop->futureTick($readChunk);
    }
    
    return $response;
});

// Example: WebSocket-like long polling
$router->get('/poll/messages', function (ServerRequestInterface $request): Promise {
    $lastId = (int) ($request->getQueryParams()['last_id'] ?? 0);
    
    return new Promise(function ($resolve) use ($lastId, $app) {
        $loop = $app->make(LoopInterface::class);
        $timeout = null;
        $checkTimer = null;
        
        // Simulate checking for new messages
        $messages = [];
        $checkForMessages = function () use (&$messages, $lastId, &$checkTimer, &$timeout, $loop, $resolve) {
            // Simulate new message arrival
            if (random_int(1, 3) === 1) {
                $newId = $lastId + 1;
                $messages[] = [
                    'id' => $newId,
                    'text' => 'Message #' . $newId,
                    'timestamp' => time(),
                ];
                
                // Cancel timers and resolve
                if ($checkTimer) {
                    $loop->cancelTimer($checkTimer);
                }
                if ($timeout) {
                    $loop->cancelTimer($timeout);
                }
                
                $resolve(Response::json(['messages' => $messages]));
            }
        };
        
        // Check every 500ms
        $checkTimer = $loop->addPeriodicTimer(0.5, $checkForMessages);
        
        // Timeout after 30 seconds
        $timeout = $loop->addTimer(30.0, function () use (&$checkTimer, $loop, $resolve) {
            if ($checkTimer) {
                $loop->cancelTimer($checkTimer);
            }
            $resolve(Response::json(['messages' => []]));
        });
    });
});

// Example: High-performance batch processing
$router->post('/batch/process', function (ServerRequestInterface $request): Promise {
    $body = json_decode((string) $request->getBody(), true);
    $items = $body['items'] ?? [];
    
    return new Promise(function ($resolve) use ($items, $app) {
        $loop = $app->make(LoopInterface::class);
        $results = [];
        $pending = count($items);
        
        if ($pending === 0) {
            $resolve(Response::json(['results' => []]));
            return;
        }
        
        foreach ($items as $index => $item) {
            // Process each item asynchronously
            $loop->futureTick(function () use ($item, $index, &$results, &$pending, $resolve) {
                // Simulate processing
                $results[$index] = [
                    'input' => $item,
                    'output' => strtoupper($item),
                    'processed_at' => microtime(true),
                ];
                
                $pending--;
                if ($pending === 0) {
                    ksort($results);
                    $resolve(Response::json(['results' => array_values($results)]));
                }
            });
        }
    });
});

// Example: Using PivotPHP 1.1.0 hooks system
$app->addAction('request.received', function (ServerRequestInterface $request) {
    echo sprintf("[%s] Request: %s %s\n", date('H:i:s'), $request->getMethod(), $request->getUri()->getPath());
});

$app->addAction('response.sent', function (ResponseInterface $response) {
    echo sprintf("[%s] Response: %d\n", date('H:i:s'), $response->getStatusCode());
});

// Add custom middleware using PivotPHP 1.1.0 middleware system
$app->use(function (ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface {
    // Add performance monitoring header
    $start = microtime(true);
    $response = $next($request, $response);
    $duration = round((microtime(true) - $start) * 1000, 2);
    
    return $response
        ->withHeader('X-Processing-Time', $duration . 'ms')
        ->withHeader('X-Powered-By', 'PivotPHP/1.1.0 + ReactPHP');
});

$server = $app->make(ReactServer::class);

$address = $_SERVER['argv'][1] ?? '0.0.0.0:8080';

echo "Starting PivotPHP ReactPHP server with advanced features on http://{$address}\n";
echo "Available endpoints:\n";
echo "  - GET  /sse/events      - Server-Sent Events stream\n";
echo "  - GET  /stream/download - Stream file download\n";
echo "  - GET  /poll/messages   - Long polling example\n";
echo "  - POST /batch/process   - Batch processing with async\n";
echo "\nFeatures demonstrated:\n";
echo "  - PivotPHP 1.1.0 high-performance mode\n";
echo "  - Middleware aliases (rate-limiter, circuit-breaker)\n";
echo "  - Hooks system integration\n";
echo "  - Streaming responses\n";
echo "  - Async processing with ReactPHP\n";
echo "\nPress Ctrl+C to stop the server\n\n";

$server->listen($address);