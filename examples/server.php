<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PivotPHP\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Providers\ReactPHPServiceProvider;
use PivotPHP\ReactPHP\Server\ReactServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$app = new Application(__DIR__);

$app->register(new ReactPHPServiceProvider());

$router = $app->get(Router::class);

$router->get('/', function (): ResponseInterface {
    return Response::json([
        'message' => 'Welcome to PivotPHP with ReactPHP!',
        'timestamp' => time(),
        'memory' => memory_get_usage(true),
    ]);
});

$router->get('/hello/{name}', function (ServerRequestInterface $request, array $args): ResponseInterface {
    return Response::json([
        'message' => sprintf('Hello, %s!', $args['name']),
        'method' => $request->getMethod(),
        'headers' => $request->getHeaders(),
    ]);
});

$router->post('/echo', function (ServerRequestInterface $request): ResponseInterface {
    $body = json_decode((string) $request->getBody(), true);
    
    return Response::json([
        'received' => $body,
        'timestamp' => microtime(true),
    ]);
});

$router->get('/stream', function (): ResponseInterface {
    $data = '';
    for ($i = 0; $i < 10; $i++) {
        $data .= sprintf("Event %d: %s\n", $i, date('Y-m-d H:i:s'));
    }
    
    return Response::create($data)
        ->withHeader('Content-Type', 'text/plain')
        ->withHeader('X-Powered-By', 'PivotPHP/ReactPHP');
});

$router->get('/benchmark', function (): ResponseInterface {
    $start = microtime(true);
    $iterations = 10000;
    
    for ($i = 0; $i < $iterations; $i++) {
        $hash = hash('sha256', (string) $i);
    }
    
    $duration = microtime(true) - $start;
    
    return Response::json([
        'iterations' => $iterations,
        'duration_ms' => round($duration * 1000, 2),
        'ops_per_second' => round($iterations / $duration),
    ]);
});

$app->addGlobalMiddleware(function (ServerRequestInterface $request, callable $next): ResponseInterface {
    $start = microtime(true);
    
    $response = $next($request);
    
    $duration = round((microtime(true) - $start) * 1000, 2);
    
    return $response
        ->withHeader('X-Response-Time', $duration . 'ms')
        ->withHeader('X-Server', 'PivotPHP/ReactPHP');
});

$server = $app->get(ReactServer::class);

$address = $_SERVER['argv'][1] ?? '0.0.0.0:8080';

echo "Starting PivotPHP ReactPHP server on http://{$address}\n";
echo "Press Ctrl+C to stop the server\n\n";

$server->listen($address);