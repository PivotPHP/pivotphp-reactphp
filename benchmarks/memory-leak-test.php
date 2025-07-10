<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Providers\ReactPHPServiceProvider;
use PivotPHP\ReactPHP\Server\ReactServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;

// Memory leak detector
class MemoryLeakDetector
{
    private array $snapshots = [];
    private array $objectCounts = [];
    private float $startMemory;
    private int $gcRuns = 0;
    
    public function __construct()
    {
        $this->startMemory = memory_get_usage(true);
        $this->takeSnapshot('initial');
    }
    
    public function takeSnapshot(string $label): void
    {
        gc_collect_cycles();
        $this->gcRuns++;
        
        $this->snapshots[$label] = [
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_usage_real' => memory_get_usage(false),
            'peak_memory' => memory_get_peak_usage(true),
            'peak_memory_real' => memory_get_peak_usage(false),
            'gc_runs' => $this->gcRuns,
        ];
        
        // Count objects by class
        $objects = [];
        foreach (get_declared_classes() as $class) {
            if (strpos($class, 'PivotPHP') === 0 || strpos($class, 'React') === 0) {
                $count = $this->countObjects($class);
                if ($count > 0) {
                    $objects[$class] = $count;
                }
            }
        }
        $this->objectCounts[$label] = $objects;
    }
    
    private function countObjects(string $class): int
    {
        $count = 0;
        foreach (get_defined_vars() as $var) {
            if (is_object($var) && get_class($var) === $class) {
                $count++;
            }
        }
        return $count;
    }
    
    public function getReport(): array
    {
        $report = [
            'memory_growth' => [],
            'snapshots' => $this->snapshots,
            'object_counts' => $this->objectCounts,
            'potential_leaks' => [],
        ];
        
        // Calculate memory growth between snapshots
        $previousSnapshot = null;
        foreach ($this->snapshots as $label => $snapshot) {
            if ($previousSnapshot !== null) {
                $growth = $snapshot['memory_usage'] - $previousSnapshot['memory_usage'];
                $report['memory_growth'][$label] = [
                    'bytes' => $growth,
                    'mb' => round($growth / 1024 / 1024, 2),
                    'percentage' => round(($growth / $previousSnapshot['memory_usage']) * 100, 2),
                ];
                
                // Detect potential leaks (continuous growth > 1MB)
                if ($growth > 1024 * 1024) {
                    $report['potential_leaks'][] = [
                        'between' => [$previousLabel ?? 'unknown', $label],
                        'growth_mb' => round($growth / 1024 / 1024, 2),
                    ];
                }
            }
            $previousSnapshot = $snapshot;
            $previousLabel = $label;
        }
        
        return $report;
    }
}

$app = new Application(__DIR__);
$app->register(ReactPHPServiceProvider::class);

$router = $app->make(Router::class);
$detector = new MemoryLeakDetector();

// Store detector in container
$app->getContainer()->instance('memory.detector', $detector);

// Test scenarios for memory leaks

// 1. Static variable accumulation (common leak pattern)
$router->get('/leak/static', function (): ResponseInterface {
    static $accumulator = [];
    
    // This will leak memory as array grows
    for ($i = 0; $i < 100; $i++) {
        $accumulator[] = str_repeat('leak', 100);
    }
    
    return Response::json([
        'accumulated_items' => count($accumulator),
        'memory_used' => memory_get_usage(true),
    ]);
});

// 2. Circular reference test
$router->get('/leak/circular', function (): ResponseInterface {
    class Node {
        public $data;
        public $next;
        public function __construct($data) {
            $this->data = $data;
        }
    }
    
    $nodes = [];
    for ($i = 0; $i < 100; $i++) {
        $node1 = new Node('data1');
        $node2 = new Node('data2');
        // Create circular reference
        $node1->next = $node2;
        $node2->next = $node1;
        $nodes[] = $node1;
    }
    
    // Should be cleaned by gc_collect_cycles()
    unset($nodes);
    gc_collect_cycles();
    
    return Response::json([
        'test' => 'circular_reference',
        'memory_used' => memory_get_usage(true),
    ]);
});

// 3. Event listener accumulation
$router->get('/leak/events', function () use ($app): ResponseInterface {
    static $listenerCount = 0;
    
    if ($app->getContainer()->has('events')) {
        $events = $app->make('events');
        
        // This could leak if listeners are not removed
        $events->listen('test.event', function () {
            // Do nothing
        });
        
        $listenerCount++;
    }
    
    return Response::json([
        'listeners_added' => $listenerCount,
        'memory_used' => memory_get_usage(true),
    ]);
});

// 4. Large object retention
$router->get('/leak/objects', function (): ResponseInterface {
    static $objectCache = [];
    
    // Create large objects
    for ($i = 0; $i < 10; $i++) {
        $obj = new stdClass();
        $obj->data = str_repeat('x', 1024 * 100); // 100KB per object
        $obj->metadata = range(1, 1000);
        $objectCache[] = $obj;
    }
    
    // Try to clean old objects (keep last 50)
    if (count($objectCache) > 50) {
        $objectCache = array_slice($objectCache, -50);
    }
    
    return Response::json([
        'cached_objects' => count($objectCache),
        'memory_used' => memory_get_usage(true),
    ]);
});

// 5. Closure capture test
$router->get('/leak/closures', function (): ResponseInterface {
    static $closures = [];
    
    $largeData = str_repeat('closure', 10000);
    
    // Closures can capture variables and prevent GC
    $closure = function () use ($largeData) {
        return strlen($largeData);
    };
    
    $closures[] = $closure;
    
    return Response::json([
        'closures_stored' => count($closures),
        'memory_used' => memory_get_usage(true),
    ]);
});

// Memory analysis endpoint
$router->get('/memory/analyze', function () use ($app): ResponseInterface {
    $detector = $app->make('memory.detector');
    $detector->takeSnapshot('current');
    
    return Response::json($detector->getReport());
});

// Force garbage collection
$router->post('/memory/gc', function (): ResponseInterface {
    $before = memory_get_usage(true);
    $cycles = gc_collect_cycles();
    $after = memory_get_usage(true);
    
    return Response::json([
        'cycles_collected' => $cycles,
        'memory_before' => $before,
        'memory_after' => $after,
        'memory_freed' => $before - $after,
        'memory_freed_mb' => round(($before - $after) / 1024 / 1024, 2),
    ]);
});

// Add middleware to track memory per request
$requestCount = 0;
$app->use(function (ServerRequestInterface $request, ResponseInterface $response, callable $next) use (&$requestCount, $detector): ResponseInterface {
    $requestCount++;
    
    // Take snapshot every 100 requests
    if ($requestCount % 100 === 0) {
        $detector->takeSnapshot("request_$requestCount");
    }
    
    $response = $next($request, $response);
    
    return $response->withHeader('X-Memory-Usage', (string) memory_get_usage(true));
});

$server = $app->make(ReactServer::class);
$loop = $app->make(LoopInterface::class);

// Periodic memory reporting
$loop->addPeriodicTimer(60.0, function () use ($detector) {
    static $iteration = 0;
    $iteration++;
    
    $detector->takeSnapshot("timer_$iteration");
    $report = $detector->getReport();
    
    echo sprintf(
        "[%s] Memory Report - Usage: %.2f MB, Peak: %.2f MB, Growth: %s\n",
        date('H:i:s'),
        memory_get_usage(true) / 1024 / 1024,
        memory_get_peak_usage(true) / 1024 / 1024,
        end($report['memory_growth'])['mb'] ?? 'N/A'
    );
    
    if ($report['potential_leaks'] !== []) {
        echo "⚠️  Potential memory leaks detected:\n";
        foreach ($report['potential_leaks'] as $leak) {
            echo sprintf("   - Growth of %.2f MB between %s and %s\n", 
                $leak['growth_mb'],
                $leak['between'][0],
                $leak['between'][1]
            );
        }
    }
});

$address = $_SERVER['argv'][1] ?? '0.0.0.0:8081';

echo "Starting Memory Leak Detection Server on http://{$address}\n";
echo "Endpoints:\n";
echo "  - GET  /leak/static    - Test static variable accumulation\n";
echo "  - GET  /leak/circular  - Test circular references\n";
echo "  - GET  /leak/events    - Test event listener accumulation\n";
echo "  - GET  /leak/objects   - Test large object retention\n";
echo "  - GET  /leak/closures  - Test closure capture\n";
echo "  - GET  /memory/analyze - Get memory analysis report\n";
echo "  - POST /memory/gc      - Force garbage collection\n";
echo "\nMemory reports will be printed every 60 seconds.\n";
echo "Press Ctrl+C to stop the server\n\n";

$server->listen($address);