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

// Performance monitoring class
class PerformanceMonitor
{
    private array $metrics = [];
    private float $startTime;
    private int $requestCount = 0;
    private array $responseTimes = [];
    private float $peakMemory = 0;
    
    public function __construct()
    {
        $this->startTime = microtime(true);
    }
    
    public function recordRequest(float $duration, int $memory): void
    {
        $this->requestCount++;
        $this->responseTimes[] = $duration;
        $this->peakMemory = max($this->peakMemory, $memory);
        
        // Keep only last 1000 response times to avoid memory issues
        if (count($this->responseTimes) > 1000) {
            array_shift($this->responseTimes);
        }
    }
    
    public function getMetrics(): array
    {
        $uptime = microtime(true) - $this->startTime;
        $avgResponseTime = count($this->responseTimes) > 0 
            ? array_sum($this->responseTimes) / count($this->responseTimes) 
            : 0;
        
        return [
            'uptime_seconds' => round($uptime, 2),
            'total_requests' => $this->requestCount,
            'requests_per_second' => round($this->requestCount / $uptime, 2),
            'average_response_time_ms' => round($avgResponseTime, 2),
            'min_response_time_ms' => count($this->responseTimes) > 0 ? round(min($this->responseTimes), 2) : 0,
            'max_response_time_ms' => count($this->responseTimes) > 0 ? round(max($this->responseTimes), 2) : 0,
            'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round($this->peakMemory / 1024 / 1024, 2),
            'gc_runs' => gc_collect_cycles(),
        ];
    }
}

$app = new Application(__DIR__);

// Enable high-performance mode
if (class_exists(HighPerformanceMode::class)) {
    HighPerformanceMode::enable(HighPerformanceMode::PROFILE_HIGH);
}

$app->register(ReactPHPServiceProvider::class);

$router = $app->make(Router::class);
$monitor = new PerformanceMonitor();

// Store monitor in container for access in middleware
$app->getContainer()->instance('performance.monitor', $monitor);

// Add performance monitoring middleware
$app->use(function (ServerRequestInterface $request, ResponseInterface $response, callable $next) use ($monitor): ResponseInterface {
    $start = microtime(true);
    $response = $next($request, $response);
    $duration = (microtime(true) - $start) * 1000;
    
    $monitor->recordRequest($duration, memory_get_usage(true));
    
    return $response->withHeader('X-Response-Time', round($duration, 2) . 'ms');
});

// Benchmark endpoints

// 1. Simple JSON response (baseline)
$router->get('/benchmark/json', function (): ResponseInterface {
    return Response::json([
        'status' => 'ok',
        'timestamp' => microtime(true),
        'data' => 'Hello, World!',
    ]);
});

// 2. Complex JSON with nested data
$router->get('/benchmark/complex-json', function (): ResponseInterface {
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        $data[] = [
            'id' => $i,
            'name' => 'Item ' . $i,
            'attributes' => [
                'created_at' => time(),
                'updated_at' => time(),
                'tags' => range(1, 10),
            ],
        ];
    }
    
    return Response::json(['items' => $data]);
});

// 3. Memory intensive operation
$router->get('/benchmark/memory', function (): ResponseInterface {
    $data = [];
    for ($i = 0; $i < 1000; $i++) {
        $data[] = str_repeat('x', 1024); // 1KB per item
    }
    
    $result = [
        'processed' => count($data),
        'memory_used' => memory_get_usage(true),
    ];
    
    // Clear data to test garbage collection
    unset($data);
    gc_collect_cycles();
    
    return Response::json($result);
});

// 4. CPU intensive operation
$router->get('/benchmark/cpu', function (): ResponseInterface {
    $start = microtime(true);
    $iterations = 10000;
    $result = 0;
    
    for ($i = 0; $i < $iterations; $i++) {
        $result += sqrt($i) * sin($i) * cos($i);
    }
    
    return Response::json([
        'iterations' => $iterations,
        'result' => $result,
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
    ]);
});

// 5. Database simulation (I/O bound)
$router->get('/benchmark/io', function (): ResponseInterface {
    $start = microtime(true);
    
    // Simulate database queries with sleep
    usleep(10000); // 10ms
    
    return Response::json([
        'query_time_ms' => round((microtime(true) - $start) * 1000, 2),
        'rows_affected' => rand(1, 100),
    ]);
});

// 6. Concurrent request handling test
$router->get('/benchmark/concurrent/{id}', function (ServerRequestInterface $request, array $args): ResponseInterface {
    $id = $args['id'] ?? 'unknown';
    $delay = rand(10, 50); // Random delay between 10-50ms
    
    usleep($delay * 1000);
    
    return Response::json([
        'request_id' => $id,
        'delay_ms' => $delay,
        'timestamp' => microtime(true),
    ]);
});

// 7. Large response test
$router->get('/benchmark/large-response', function (): ResponseInterface {
    $size = 1024 * 1024; // 1MB
    $data = str_repeat('a', $size);
    
    return Response::create($data)
        ->withHeader('Content-Type', 'text/plain')
        ->withHeader('Content-Length', (string) $size);
});

// 8. Metrics endpoint
$router->get('/metrics', function () use ($app): ResponseInterface {
    $monitor = $app->make('performance.monitor');
    $loop = $app->make(LoopInterface::class);
    
    $metrics = $monitor->getMetrics();
    $metrics['event_loop'] = [
        'class' => get_class($loop),
        'is_running' => method_exists($loop, 'isRunning') ? $loop->isRunning() : 'unknown',
    ];
    
    // Add PivotPHP specific metrics if available
    if (class_exists(HighPerformanceMode::class)) {
        $metrics['high_performance_mode'] = 'enabled';
    }
    
    return Response::json($metrics);
});

// 9. Stress test endpoint
$router->post('/benchmark/stress', function (ServerRequestInterface $request): ResponseInterface {
    $body = json_decode((string) $request->getBody(), true);
    $operations = $body['operations'] ?? 100;
    
    $results = [];
    $start = microtime(true);
    
    for ($i = 0; $i < $operations; $i++) {
        // Mix of operations
        if ($i % 3 === 0) {
            // Memory operation
            $data = str_repeat('x', rand(100, 1000));
            $results[] = strlen($data);
        } elseif ($i % 3 === 1) {
            // CPU operation
            $results[] = sqrt($i) * sin($i);
        } else {
            // String operation
            $results[] = md5((string) $i);
        }
    }
    
    return Response::json([
        'operations' => $operations,
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        'ops_per_second' => round($operations / (microtime(true) - $start), 2),
    ]);
});

$server = $app->make(ReactServer::class);

// Add periodic memory reporting
$loop = $app->make(LoopInterface::class);
$loop->addPeriodicTimer(30.0, function () use ($monitor) {
    $metrics = $monitor->getMetrics();
    echo sprintf(
        "[%s] Performance: %d requests, %.2f req/s, %.2f ms avg response, %.2f MB memory\n",
        date('H:i:s'),
        $metrics['total_requests'],
        $metrics['requests_per_second'],
        $metrics['average_response_time_ms'],
        $metrics['current_memory_mb']
    );
});

$address = $_SERVER['argv'][1] ?? '0.0.0.0:8080';

echo "Starting PivotPHP ReactPHP Performance Benchmark Server on http://{$address}\n";
echo "Endpoints:\n";
echo "  - GET  /benchmark/json           - Simple JSON response\n";
echo "  - GET  /benchmark/complex-json   - Complex nested JSON (100 items)\n";
echo "  - GET  /benchmark/memory         - Memory intensive operation\n";
echo "  - GET  /benchmark/cpu            - CPU intensive operation\n";
echo "  - GET  /benchmark/io             - I/O simulation\n";
echo "  - GET  /benchmark/concurrent/:id - Concurrent request test\n";
echo "  - GET  /benchmark/large-response - 1MB response\n";
echo "  - POST /benchmark/stress         - Stress test endpoint\n";
echo "  - GET  /metrics                  - Performance metrics\n";
echo "\nPerformance metrics will be printed every 30 seconds.\n";
echo "Press Ctrl+C to stop the server\n\n";

$server->listen($address);