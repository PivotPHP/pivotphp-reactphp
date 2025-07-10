<?php

declare(strict_types=1);

/**
 * Comparison script between ReactPHP and traditional PHP-FPM
 * 
 * This script creates identical endpoints that can be tested
 * in both ReactPHP and PHP-FPM environments for comparison.
 */

require __DIR__ . '/../vendor/autoload.php';

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;

$app = new Application(__DIR__);
$router = $app->make(Router::class);

// Test 1: Bootstrap overhead
$router->get('/test/bootstrap', function (): \Psr\Http\Message\ResponseInterface {
    return Response::json([
        'test' => 'bootstrap',
        'timestamp' => microtime(true),
        'memory' => memory_get_usage(true),
        'included_files' => count(get_included_files()),
    ]);
});

// Test 2: Database connection (simulated)
$router->get('/test/database', function (): \Psr\Http\Message\ResponseInterface {
    static $connectionTime = null;
    
    if ($connectionTime === null) {
        // Simulate connection time
        $start = microtime(true);
        usleep(50000); // 50ms connection time
        $connectionTime = microtime(true) - $start;
    }
    
    // Simulate query
    usleep(10000); // 10ms query time
    
    return Response::json([
        'test' => 'database',
        'connection_time' => $connectionTime,
        'connection_reused' => $connectionTime < 0.01,
        'query_time' => 0.01,
    ]);
});

// Test 3: Session handling
$router->get('/test/session', function (): \Psr\Http\Message\ResponseInterface {
    static $sessions = [];
    
    $sessionId = $_COOKIE['PHPSESSID'] ?? bin2hex(random_bytes(16));
    
    if (!isset($sessions[$sessionId])) {
        $sessions[$sessionId] = [
            'created' => time(),
            'requests' => 0,
        ];
    }
    
    $sessions[$sessionId]['requests']++;
    $sessions[$sessionId]['last_access'] = time();
    
    return Response::json([
        'test' => 'session',
        'session_id' => $sessionId,
        'session_data' => $sessions[$sessionId],
        'total_sessions' => count($sessions),
    ]);
});

// Test 4: File operations
$router->get('/test/file', function (): \Psr\Http\Message\ResponseInterface {
    $tempFile = sys_get_temp_dir() . '/benchmark_' . getmypid() . '.tmp';
    
    $start = microtime(true);
    
    // Write
    file_put_contents($tempFile, str_repeat('x', 1024 * 10)); // 10KB
    
    // Read
    $content = file_get_contents($tempFile);
    
    // Delete
    unlink($tempFile);
    
    $duration = microtime(true) - $start;
    
    return Response::json([
        'test' => 'file_operations',
        'operations' => ['write', 'read', 'delete'],
        'file_size' => 10240,
        'duration_ms' => round($duration * 1000, 2),
    ]);
});

// Test 5: CPU intensive
$router->get('/test/cpu', function (): \Psr\Http\Message\ResponseInterface {
    $start = microtime(true);
    $result = 0;
    
    for ($i = 0; $i < 10000; $i++) {
        $result += sqrt($i) * sin($i);
    }
    
    return Response::json([
        'test' => 'cpu_intensive',
        'iterations' => 10000,
        'result' => $result,
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
    ]);
});

// Test 6: Memory allocation
$router->get('/test/memory', function (): \Psr\Http\Message\ResponseInterface {
    $start = memory_get_usage(true);
    
    $data = [];
    for ($i = 0; $i < 1000; $i++) {
        $data[] = str_repeat('x', 1024); // 1KB per item
    }
    
    $peak = memory_get_peak_usage(true);
    
    unset($data);
    gc_collect_cycles();
    
    $end = memory_get_usage(true);
    
    return Response::json([
        'test' => 'memory_allocation',
        'allocated_mb' => round(($peak - $start) / 1024 / 1024, 2),
        'freed_mb' => round(($peak - $end) / 1024 / 1024, 2),
        'gc_effective' => ($end <= $start),
    ]);
});

// Test 7: Concurrency simulation
$router->get('/test/concurrent', function (): \Psr\Http\Message\ResponseInterface {
    static $activeRequests = 0;
    
    $activeRequests++;
    $requestId = uniqid();
    
    // Simulate work
    usleep(rand(10000, 50000)); // 10-50ms
    
    $result = [
        'test' => 'concurrency',
        'request_id' => $requestId,
        'active_requests' => $activeRequests,
        'timestamp' => microtime(true),
    ];
    
    $activeRequests--;
    
    return Response::json($result);
});

// Comparison results endpoint
$router->get('/compare/results', function (): \Psr\Http\Message\ResponseInterface {
    return Response::json([
        'comparison' => 'ReactPHP vs PHP-FPM',
        'metrics' => [
            'startup_overhead' => [
                'reactphp' => 'One-time application bootstrap',
                'php_fpm' => 'Bootstrap on every request',
            ],
            'memory_usage' => [
                'reactphp' => 'Shared memory across requests',
                'php_fpm' => 'Isolated memory per request',
            ],
            'connection_pooling' => [
                'reactphp' => 'Persistent connections possible',
                'php_fpm' => 'New connections per request (unless pooled)',
            ],
            'concurrency' => [
                'reactphp' => 'Event-loop based, non-blocking',
                'php_fpm' => 'Process/thread based, blocking',
            ],
            'state_management' => [
                'reactphp' => 'In-memory state persistence',
                'php_fpm' => 'Stateless, requires external storage',
            ],
        ],
    ]);
});

// For PHP-FPM mode
if (php_sapi_name() !== 'cli') {
    // Running under PHP-FPM/Apache/Nginx
    $request = $_SERVER['REQUEST_URI'];
    $method = $_SERVER['REQUEST_METHOD'];
    
    $route = $router->match($method, parse_url($request, PHP_URL_PATH));
    
    if ($route) {
        $response = $route['handler']();
        
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value");
            }
        }
        
        echo $response->getBody();
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
    }
    
    exit;
}

// For ReactPHP mode
use PivotPHP\ReactPHP\Providers\ReactPHPServiceProvider;
use PivotPHP\ReactPHP\Server\ReactServer;

$app->register(ReactPHPServiceProvider::class);
$server = $app->make(ReactServer::class);

$address = $_SERVER['argv'][1] ?? '0.0.0.0:8082';

echo "Starting Comparison Server on http://{$address}\n";
echo "This server can be used to compare ReactPHP vs PHP-FPM performance.\n";
echo "\nTest endpoints:\n";
echo "  - /test/bootstrap  - Test bootstrap overhead\n";
echo "  - /test/database   - Test database connection reuse\n";
echo "  - /test/session    - Test session handling\n";
echo "  - /test/file       - Test file operations\n";
echo "  - /test/cpu        - Test CPU intensive operations\n";
echo "  - /test/memory     - Test memory allocation/GC\n";
echo "  - /test/concurrent - Test concurrent request handling\n";
echo "  - /compare/results - View comparison summary\n";
echo "\nTo test with PHP-FPM, deploy this script to a web server.\n";
echo "Press Ctrl+C to stop the server\n\n";

$server->listen($address);