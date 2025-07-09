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
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Http\Browser;

$app = new Application(__DIR__);

$app->register(new ReactPHPServiceProvider());

$router = $app->get(Router::class);
$loop = $app->get(LoopInterface::class);
$browser = new Browser(null, $loop);

$router->get('/async/fetch', function () use ($browser): Promise {
    return new Promise(function ($resolve) use ($browser) {
        $browser->get('https://api.github.com/repos/pivotphp/core')->then(
            function ($response) use ($resolve) {
                $data = json_decode((string) $response->getBody(), true);
                
                $resolve(Response::json([
                    'repository' => $data['name'] ?? 'unknown',
                    'description' => $data['description'] ?? '',
                    'stars' => $data['stargazers_count'] ?? 0,
                    'language' => $data['language'] ?? 'PHP',
                ]));
            },
            function ($error) use ($resolve) {
                $resolve(Response::json([
                    'error' => 'Failed to fetch repository data',
                    'message' => $error->getMessage(),
                ], 500));
            }
        );
    });
});

$router->get('/async/timer', function () use ($loop): Promise {
    return new Promise(function ($resolve) use ($loop) {
        $startTime = microtime(true);
        $events = [];
        
        $loop->addTimer(0.1, function () use (&$events, $startTime) {
            $events[] = [
                'event' => 'timer-100ms',
                'elapsed' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        });
        
        $loop->addTimer(0.2, function () use (&$events, $startTime) {
            $events[] = [
                'event' => 'timer-200ms',
                'elapsed' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        });
        
        $loop->addTimer(0.3, function () use (&$events, $startTime, $resolve) {
            $events[] = [
                'event' => 'timer-300ms',
                'elapsed' => round((microtime(true) - $startTime) * 1000, 2),
            ];
            
            $resolve(Response::json([
                'message' => 'All timers completed',
                'events' => $events,
                'total_elapsed' => round((microtime(true) - $startTime) * 1000, 2),
            ]));
        });
    });
});

$router->get('/async/parallel', function () use ($browser): Promise {
    return new Promise(function ($resolve) use ($browser) {
        $promises = [
            'github' => $browser->get('https://api.github.com'),
            'time' => $browser->get('http://worldtimeapi.org/api/timezone/UTC'),
        ];
        
        \React\Promise\all($promises)->then(
            function ($responses) use ($resolve) {
                $results = [];
                
                foreach ($responses as $key => $response) {
                    $results[$key] = [
                        'status' => $response->getStatusCode(),
                        'headers' => $response->getHeaders(),
                        'size' => strlen((string) $response->getBody()),
                    ];
                }
                
                $resolve(Response::json([
                    'message' => 'All requests completed',
                    'results' => $results,
                ]));
            },
            function ($error) use ($resolve) {
                $resolve(Response::json([
                    'error' => 'One or more requests failed',
                    'message' => $error->getMessage(),
                ], 500));
            }
        );
    });
});

$router->get('/async/periodic', function (ServerRequestInterface $request) use ($loop): ResponseInterface {
    $count = 0;
    $maxCount = 5;
    $interval = 1.0;
    
    $periodic = $loop->addPeriodicTimer($interval, function ($timer) use (&$count, $maxCount, $loop) {
        $count++;
        echo sprintf("[%s] Periodic timer tick #%d\n", date('H:i:s'), $count);
        
        if ($count >= $maxCount) {
            $loop->cancelTimer($timer);
            echo "Periodic timer cancelled after {$maxCount} ticks\n";
        }
    });
    
    return Response::json([
        'message' => 'Periodic timer started',
        'interval' => $interval,
        'max_count' => $maxCount,
        'info' => 'Check server console for output',
    ]);
});

$server = $app->get(ReactServer::class);

$address = $_SERVER['argv'][1] ?? '0.0.0.0:8080';

echo "Starting PivotPHP ReactPHP async example server on http://{$address}\n";
echo "Available endpoints:\n";
echo "  - GET /async/fetch    - Fetch data from external API\n";
echo "  - GET /async/timer    - Demonstrate timer functionality\n";
echo "  - GET /async/parallel - Make parallel HTTP requests\n";
echo "  - GET /async/periodic - Start a periodic timer (check console)\n";
echo "\nPress Ctrl+C to stop the server\n\n";

$server->listen($address);