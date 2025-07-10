<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Performance;

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Security\MemoryGuard;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\ReactPHP\Tests\Helpers\JsonHelper;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;
use React\Promise\Promise;
use Psr\Log\LoggerInterface;

final class StressTest extends TestCase
{
    protected Application $app;
    private ReactServer $server;
    private MemoryGuard $memoryGuard;
    private array $metrics = [];

    public function getMemoryGuard(): MemoryGuard
    {
        return $this->memoryGuard;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create application with test configuration
        $this->app = new Application(__DIR__);

        // Create memory guard for monitoring
        $this->memoryGuard = new MemoryGuard(
            Loop::get(),
            [
                'max_memory' => 512 * 1024 * 1024, // 512MB
                'warning_threshold' => 400 * 1024 * 1024, // 400MB
                'check_interval' => 1,
            ]
        );

        // Create server
        $this->server = new ReactServer(
            $this->app,
            Loop::get(),
            null,
            [
                'debug' => false,
                'streaming' => true,
                'max_concurrent_requests' => 1000,
            ]
        );

        $this->setupStressRoutes();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function setupStressRoutes(): void
    {
        $router = $this->app->make(Router::class);
        assert($router instanceof Router);

        // Simple route
        $router::get('/ping', function () {
            return (new Response())->json(['status' => 'ok']);
        });

        // CPU intensive route
        $router::get('/cpu-intensive', function () {
            $result = 0;
            for ($i = 0; $i < 10000; $i++) {
                $result += sqrt($i) * sin($i);
            }
            return (new Response())->json(['result' => $result]);
        });

        // Memory intensive route
        $router::get('/memory-intensive', function () {
            $data = [];
            for ($i = 0; $i < 1000; $i++) {
                $data[] = str_repeat('x', 1000); // 1KB each
            }
            return (new Response())->json(['size' => count($data)]);
        });

        // Database simulation route
        $router::get('/db-simulation', function () {
            $results = [];
            for ($i = 0; $i < 10; $i++) {
                $results[] = [
                    'id' => $i,
                    'data' => bin2hex(random_bytes(32)),
                    'timestamp' => microtime(true),
                ];
            }
            return (new Response())->json($results);
        });

        // Large response route
        $router::get('/large-response', function () {
            $data = [];
            for ($i = 0; $i < 1000; $i++) {
                $data[] = [
                    'id' => $i,
                    'uuid' => bin2hex(random_bytes(16)),
                    'data' => str_repeat('x', 100),
                ];
            }
            return (new Response())->json($data);
        });
    }

    /**
     * @group stress
     */
    public function testHighConcurrentRequests(): void
    {
        self::markTestSkipped('Stress tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $concurrentRequests = 100;
        $totalRequests = 1000;
        $batchSize = 100;

        $this->memoryGuard->startMonitoring();

        $startTime = microtime(true);
        $completedRequests = 0;
        $errors = 0;

        for ($batch = 0; $batch < ($totalRequests / $batchSize); $batch++) {
            $promises = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $request = new ServerRequest('GET', new Uri('http://localhost/ping'));

                $promise = $this->server->handleRequest($request)
                    ->then(
                        function ($response) use (&$completedRequests) {
                            $completedRequests++;
                            return $response;
                        },
                        function ($error) use (&$errors) {
                            $errors++;
                            throw $error;
                        }
                    );

                $promises[] = $promise;
            }

            // Wait for batch to complete
            $all = \React\Promise\all($promises);
            $completed = false;
            $all->then(function () use (&$completed) {
                $completed = true;
            });

            Loop::get()->futureTick(function () use (&$completed) {
                if ($completed) {
                    Loop::get()->stop();
                }
            });
            Loop::get()->run();
        }

        $duration = microtime(true) - $startTime;
        $requestsPerSecond = $completedRequests / $duration;

        $this->metrics['high_concurrent'] = [
            'total_requests' => $totalRequests,
            'completed_requests' => $completedRequests,
            'errors' => $errors,
            'duration' => $duration,
            'requests_per_second' => $requestsPerSecond,
            'memory_stats' => $this->memoryGuard->getStats(),
        ];

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertGreaterThan
        self::assertGreaterThan(0, $completedRequests);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertLessThan
        self::assertLessThan($totalRequests * 0.01, $errors); // Less than 1% error rate
    }

    /**
     * @group stress
     */
    public function testMemoryUnderLoad(): void
    {
        self::markTestSkipped('Stress tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $requests = 100;
        $memorySnapshots = [];

        $this->memoryGuard->startMonitoring();
        $this->memoryGuard->onMemoryLeak(function ($data) {
            self::fail('Memory leak detected: ' . json_encode($data));
        });

        // Take initial snapshot
        $memorySnapshots[] = [
            'time' => 0,
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];

        // Make memory-intensive requests
        for ($i = 0; $i < $requests; $i++) {
            $request = new ServerRequest('GET', new Uri('http://localhost/memory-intensive'));

            $response = null;
            $this->server->handleRequest($request)->then(function ($res) use (&$response) {
                $response = $res;
            });

            Loop::get()->futureTick(function () {
                Loop::get()->stop();
            });
            Loop::get()->run();

            // Take snapshot every 10 requests
            if ($i % 10 === 0) {
                gc_collect_cycles();
                $memorySnapshots[] = [
                    'time' => $i,
                    'memory' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true),
                ];
            }
        }

        // Final snapshot
        gc_collect_cycles();
        $memorySnapshots[] = [
            'time' => $requests,
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];

        // Analyze memory growth
        $initialMemory = $memorySnapshots[0]['memory'];
        $finalMemory = $memorySnapshots[count($memorySnapshots) - 1]['memory'];
        $memoryGrowth = $finalMemory - $initialMemory;
        $memoryGrowthPercentage = ($memoryGrowth / $initialMemory) * 100;

        $this->metrics['memory_under_load'] = [
            'snapshots' => $memorySnapshots,
            'initial_memory' => $initialMemory,
            'final_memory' => $finalMemory,
            'memory_growth' => $memoryGrowth,
            'growth_percentage' => $memoryGrowthPercentage,
            'memory_guard_stats' => $this->memoryGuard->getStats(),
        ];

        // Memory growth should be reasonable (less than 50%)
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertLessThan
        self::assertLessThan(50, $memoryGrowthPercentage);
    }

    /**
     * @group stress
     */
    public function testCpuIntensiveLoad(): void
    {
        self::markTestSkipped('Stress tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $concurrentRequests = 10;
        $totalRequests = 100;

        $startTime = microtime(true);
        $responseTimes = [];

        for ($batch = 0; $batch < ($totalRequests / $concurrentRequests); $batch++) {
            $promises = [];
            $batchStart = microtime(true);

            for ($i = 0; $i < $concurrentRequests; $i++) {
                $requestStart = microtime(true);
                $request = new ServerRequest('GET', new Uri('http://localhost/cpu-intensive'));

                $promise = $this->server->handleRequest($request)
                    ->then(function ($response) use ($requestStart, &$responseTimes) {
                        $responseTimes[] = microtime(true) - $requestStart;
                        return $response;
                    });

                $promises[] = $promise;
            }

            // Wait for batch
            \React\Promise\all($promises)->then(function () {
                Loop::get()->stop();
            });
            Loop::get()->run();
        }

        $totalDuration = microtime(true) - $startTime;

        // Calculate statistics
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $minResponseTime = min($responseTimes);
        $maxResponseTime = max($responseTimes);

        // Calculate percentiles
        sort($responseTimes);
        $p50 = $responseTimes[intval(count($responseTimes) * 0.5)];
        $p95 = $responseTimes[intval(count($responseTimes) * 0.95)];
        $p99 = $responseTimes[intval(count($responseTimes) * 0.99)];

        $this->metrics['cpu_intensive_load'] = [
            'total_requests' => $totalRequests,
            'concurrent_requests' => $concurrentRequests,
            'total_duration' => $totalDuration,
            'avg_response_time' => $avgResponseTime,
            'min_response_time' => $minResponseTime,
            'max_response_time' => $maxResponseTime,
            'p50' => $p50,
            'p95' => $p95,
            'p99' => $p99,
            'throughput' => $totalRequests / $totalDuration,
        ];

        // Assert reasonable performance
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertLessThan
        self::assertLessThan(1.0, $avgResponseTime); // Average under 1 second
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertLessThan
        self::assertLessThan(2.0, $p99); // 99th percentile under 2 seconds
    }

    /**
     * @group stress
     */
    public function testLargeResponseHandling(): void
    {
        self::markTestSkipped('Stress tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $requests = 50;
        $startTime = microtime(true);
        $bytesTransferred = 0;

        for ($i = 0; $i < $requests; $i++) {
            $request = new ServerRequest('GET', new Uri('http://localhost/large-response'));

            $response = null;
            $this->server->handleRequest($request)->then(function ($res) use (&$response, &$bytesTransferred) {
                $response = $res;
                $bytesTransferred += strlen((string) $res->getBody());
            });

            Loop::get()->futureTick(function () {
                Loop::get()->stop();
            });
            Loop::get()->run();

            // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertNotNull
            self::assertNotNull($response);
            // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
            self::assertEquals(200, $response->getStatusCode());
        }

        $duration = microtime(true) - $startTime;
        $throughputMBps = ($bytesTransferred / 1024 / 1024) / $duration;

        $this->metrics['large_response'] = [
            'requests' => $requests,
            'duration' => $duration,
            'bytes_transferred' => $bytesTransferred,
            'throughput_mbps' => $throughputMBps,
            'avg_response_size' => $bytesTransferred / $requests,
        ];

        // Assert reasonable throughput (at least 10 MB/s)
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertGreaterThan
        self::assertGreaterThan(10, $throughputMBps);
    }

    /**
     * @group stress
     */
    public function testErrorRecovery(): void
    {
        self::markTestSkipped('Stress tests should be run manually');

        // @phpstan-ignore-next-line Unreachable statement - code above always terminates
        $router = $this->app->make(Router::class);

        // Add route that fails randomly
        $router->get('/unstable', function () {
            if (rand(0, 2) === 0) {
                throw new \RuntimeException('Random failure');
            }
            return Response::json(['status' => 'success']);
        });

        $requests = 100;
        $successes = 0;
        $failures = 0;

        for ($i = 0; $i < $requests; $i++) {
            $request = new ServerRequest('GET', new Uri('http://localhost/unstable'));

            $response = null;
            $this->server->handleRequest($request)->then(function ($res) use (&$response) {
                $response = $res;
            });

            Loop::get()->futureTick(function () {
                Loop::get()->stop();
            });
            Loop::get()->run();

            if ($response->getStatusCode() === 200) {
                $successes++;
            } else {
                $failures++;
            }
        }

        $this->metrics['error_recovery'] = [
            'total_requests' => $requests,
            'successes' => $successes,
            'failures' => $failures,
            'success_rate' => ($successes / $requests) * 100,
            'server_survived' => true,
        ];

        // Server should handle all requests despite errors
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        self::assertEquals($requests, $successes + $failures);
        // Success rate should be around 66% (2/3)
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertGreaterThan
        self::assertGreaterThan(50, ($successes / $requests) * 100);
    }
}
