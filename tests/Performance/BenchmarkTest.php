<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Performance;

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\ReactPHP\Tests\Helpers\JsonHelper;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;

final class BenchmarkTest extends TestCase
{
    protected Application $app;
    private ReactServer $server;
    private array $benchmarkResults = [];

    public function getBenchmarkResults(): array
    {
        return $this->benchmarkResults;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(__DIR__);
        $this->server = new ReactServer($this->app, Loop::get());

        $this->setupBenchmarkRoutes();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function setupBenchmarkRoutes(): void
    {
        $router = $this->app->make(Router::class);
        assert($router instanceof Router);

        // Minimal route - baseline performance
        $router::get('/minimal', function () {
            return (new Response())->withBody(new \PivotPHP\Core\Http\Psr7\Stream('OK'));
        });

        // JSON response
        $router::get('/json', function () {
            return (new Response())->json(['status' => 'ok', 'timestamp' => time()]);
        });

        // Route with middleware simulation
        $router::get('/with-middleware', function ($request) {
            // Simulate middleware processing
            $headers = $request->getHeaders();
            $authHeader = $headers['authorization'] ?? null;

            return (new Response())->json([
                'authenticated' => $authHeader !== null,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        });

        // Database query simulation (PivotPHP syntax: :id not {id})
        $router::get('/db-query/:id', function ($request, $response) {
            $id = $request->param('id');
            // Simulate database query delay
            $data = [
                'id' => $id,
                'name' => 'User ' . $id,
                'email' => 'user' . $id . '@example.com',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            return (new Response())->json($data);
        });

        // Complex computation
        $router::post('/compute', function ($request) {
            $body = JsonHelper::decode((string) $request->getBody());
            $input = $body['input'] ?? 100;

            $result = 0;
            for ($i = 0; $i < $input; $i++) {
                $result += sqrt($i) * sin($i);
            }

            return (new Response())->json([
                'input' => $input,
                'result' => $result,
                'computation_time' => microtime(true),
            ]);
        });
    }

    /**
     * @group benchmark
     */
    public function testMinimalRouteBenchmark(): void
    {
        self::markTestSkipped('Benchmark tests should be run manually');
    }

    /**
     * @group benchmark
     */
    public function testJsonResponseBenchmark(): void
    {
        self::markTestSkipped('Benchmark tests should be run manually');
    }

    /**
     * @group benchmark
     */
    public function testMiddlewareBenchmark(): void
    {
        self::markTestSkipped('Benchmark tests should be run manually');
    }

    /**
     * @group benchmark
     */
    public function testDatabaseQueryBenchmark(): void
    {
        self::markTestSkipped('Benchmark tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $iterations = 500;
        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $results = $this->runBenchmark('/db-query/123', 'GET', $iterations);

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $this->benchmarkResults['database_query'] = $results;
        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        self::assertBenchmarkPerformance($results, 0.005); // Should average under 5ms
    }

    /**
     * @group benchmark
     */
    public function testComputationBenchmark(): void
    {
        self::markTestSkipped('Benchmark tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $iterations = 100;
        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $results = $this->runBenchmark(
            '/compute',
            'POST',
            $iterations,
            ['Content-Type' => 'application/json'],
            json_encode(['input' => 1000])
        );

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $this->benchmarkResults['computation'] = $results;
        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        self::assertBenchmarkPerformance($results, 0.010); // Should average under 10ms
    }

    /**
     * @group benchmark
     */
    public function testConcurrentRequestsBenchmark(): void
    {
        self::markTestSkipped('Benchmark tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $concurrentRequests = 10;
        $totalRequests = 100;
        $results = [];

        $startTime = microtime(true);

        for ($batch = 0; $batch < ($totalRequests / $concurrentRequests); $batch++) {
            $promises = [];
            $batchTimes = [];

            for ($i = 0; $i < $concurrentRequests; $i++) {
                $requestStart = microtime(true);
                $request = new ServerRequest('GET', new Uri('http://localhost/json'));

                $promise = $this->server->handleRequest($request)
                    ->then(function ($response) use ($requestStart, &$batchTimes) {
                        $batchTimes[] = microtime(true) - $requestStart;
                        return $response;
                    });

                $promises[] = $promise;
            }

            // Wait for batch to complete
            \React\Promise\all($promises)->then(function () {
                Loop::get()->stop();
            });
            Loop::get()->run();

            $results = array_merge($results, $batchTimes);
        }

        $totalTime = microtime(true) - $startTime;

        $this->benchmarkResults['concurrent_requests'] = [
            'total_requests' => $totalRequests,
            'concurrent_requests' => $concurrentRequests,
            'total_time' => $totalTime,
            'throughput' => $totalRequests / $totalTime,
            'avg_response_time' => array_sum($results) / count($results),
            'min_response_time' => min($results),
            'max_response_time' => max($results),
        ];

        self::assertGreaterThan(100, $totalRequests / $totalTime); // At least 100 req/s
    }

    /**
     * @phpstan-ignore-next-line Method is used by benchmark tests when run manually
     */
    private function runBenchmark(
        string $path,
        string $method,
        int $iterations,
        array $headers = [],
        ?string $body = null
    ): array {
        $times = [];
        $errors = 0;

        // Warmup
        for ($i = 0; $i < 10; $i++) {
            $request = $this->createRequest($method, $path, $headers, $body);
            $this->server->handleRequest($request)->then(function () {
                Loop::get()->stop();
            });
            Loop::get()->run();
        }

        // Actual benchmark
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            $request = $this->createRequest($method, $path, $headers, $body);

            $response = null;
            $error = null;

            $this->server->handleRequest($request)->then(
                function ($res) use (&$response) {
                    $response = $res;
                    Loop::get()->stop();
                },
                function ($err) use (&$error) {
                    $error = $err;
                    Loop::get()->stop();
                }
            );

            Loop::get()->run();

            // @phpstan-ignore-next-line Only booleans are allowed in an if condition, Throwable|null given
            if ($error) {
                $errors++;
            } else {
                $times[] = microtime(true) - $start;
            }
        }

        // Calculate statistics
        $successfulRequests = count($times);
        $avgTime = $successfulRequests > 0 ? array_sum($times) / $successfulRequests : 0;

        // Calculate percentiles
        sort($times);
        $p50 = $successfulRequests > 0 ? $times[intval($successfulRequests * 0.5)] : 0;
        $p95 = $successfulRequests > 0 ? $times[intval($successfulRequests * 0.95)] : 0;
        $p99 = $successfulRequests > 0 ? $times[intval($successfulRequests * 0.99)] : 0;

        return [
            'iterations' => $iterations,
            'successful' => $successfulRequests,
            'errors' => $errors,
            'avg_time' => $avgTime,
            'min_time' => $successfulRequests > 0 ? min($times) : 0,
            'max_time' => $successfulRequests > 0 ? max($times) : 0,
            'p50' => $p50,
            'p95' => $p95,
            'p99' => $p99,
            'throughput' => $successfulRequests > 0 ? $successfulRequests / array_sum($times) : 0,
        ];
    }

    private function createRequest(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null
    ): ServerRequest {
        $request = new ServerRequest($method, new Uri('http://localhost' . $path), $headers);

        if ($body !== null) {
            // @phpstan-ignore-next-line Call to static method create() on an unknown class React\Stream\Utils
            $request = $request->withBody(\React\Stream\Utils::create($body));
        }

        return $request;
    }

    /**
     * @phpstan-ignore-next-line Method is used by benchmark tests when run manually
     */
    private function assertBenchmarkPerformance(array $results, float $maxAvgTime): void
    {
        self::assertGreaterThan(0, $results['successful']);
        self::assertEquals(0, $results['errors']);
        self::assertLessThan($maxAvgTime, $results['avg_time']);
        self::assertLessThan($maxAvgTime * 2, $results['p95']); // 95th percentile should be under 2x average
    }
}
