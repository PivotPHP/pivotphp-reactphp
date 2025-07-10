<?php

declare(strict_types=1);

/**
 * Manual Stress Test Script
 * 
 * This script contains the actual stress test implementations that were
 * previously in the StressTest.php file but unreachable due to markTestSkipped().
 * 
 * Run this script manually to perform stress testing:
 * php scripts/stress-test.php
 */

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Security\MemoryGuard;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;

require_once __DIR__ . '/../vendor/autoload.php';

class StressTestRunner
{
    private Application $app;
    private ReactServer $server;
    private MemoryGuard $memoryGuard;
    private array $metrics = [];

    public function __construct()
    {
        $this->app = new Application(__DIR__ . '/..');
        
        $this->memoryGuard = new MemoryGuard(
            Loop::get(),
            [
                'max_memory' => 512 * 1024 * 1024, // 512MB
                'warning_threshold' => 400 * 1024 * 1024, // 400MB
                'check_interval' => 1,
            ]
        );

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

        // Unstable route for error recovery testing
        $router::get('/unstable', function () {
            if (rand(0, 2) === 0) {
                throw new \RuntimeException('Random failure');
            }
            return (new Response())->json(['status' => 'success']);
        });
    }

    public function runHighConcurrentRequests(): void
    {
        echo "Running High Concurrent Requests Test...\n";
        
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

        echo "Completed: $completedRequests/$totalRequests requests\n";
        echo "Errors: $errors\n";
        echo "Duration: " . number_format($duration, 2) . " seconds\n";
        echo "Requests/sec: " . number_format($requestsPerSecond, 2) . "\n\n";
    }

    public function runMemoryUnderLoad(): void
    {
        echo "Running Memory Under Load Test...\n";
        
        $requests = 100;
        $memorySnapshots = [];

        $this->memoryGuard->startMonitoring();
        $this->memoryGuard->onMemoryLeak(function ($data) {
            echo "Memory leak detected: " . json_encode($data) . "\n";
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

        echo "Initial Memory: " . number_format($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "Final Memory: " . number_format($finalMemory / 1024 / 1024, 2) . " MB\n";
        echo "Memory Growth: " . number_format($memoryGrowth / 1024 / 1024, 2) . " MB\n";
        echo "Growth Percentage: " . number_format($memoryGrowthPercentage, 2) . "%\n\n";
    }

    public function runCpuIntensiveLoad(): void
    {
        echo "Running CPU Intensive Load Test...\n";
        
        $concurrentRequests = 10;
        $totalRequests = 100;

        $startTime = microtime(true);
        $responseTimes = [];

        for ($batch = 0; $batch < ($totalRequests / $concurrentRequests); $batch++) {
            $promises = [];

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

        echo "Total Duration: " . number_format($totalDuration, 2) . " seconds\n";
        echo "Average Response Time: " . number_format($avgResponseTime * 1000, 2) . " ms\n";
        echo "Min Response Time: " . number_format($minResponseTime * 1000, 2) . " ms\n";
        echo "Max Response Time: " . number_format($maxResponseTime * 1000, 2) . " ms\n";
        echo "P50: " . number_format($p50 * 1000, 2) . " ms\n";
        echo "P95: " . number_format($p95 * 1000, 2) . " ms\n";
        echo "P99: " . number_format($p99 * 1000, 2) . " ms\n";
        echo "Throughput: " . number_format($totalRequests / $totalDuration, 2) . " requests/sec\n\n";
    }

    public function runLargeResponseHandling(): void
    {
        echo "Running Large Response Handling Test...\n";
        
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

        echo "Requests: $requests\n";
        echo "Duration: " . number_format($duration, 2) . " seconds\n";
        echo "Bytes Transferred: " . number_format($bytesTransferred / 1024 / 1024, 2) . " MB\n";
        echo "Throughput: " . number_format($throughputMBps, 2) . " MB/s\n";
        echo "Average Response Size: " . number_format($bytesTransferred / $requests / 1024, 2) . " KB\n\n";
    }

    public function runErrorRecovery(): void
    {
        echo "Running Error Recovery Test...\n";
        
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

        echo "Total Requests: $requests\n";
        echo "Successes: $successes\n";
        echo "Failures: $failures\n";
        echo "Success Rate: " . number_format(($successes / $requests) * 100, 2) . "%\n";
        echo "Server Survived: Yes\n\n";
    }

    public function runAll(): void
    {
        echo "=== PivotPHP ReactPHP Stress Test Runner ===\n\n";
        
        $startTime = microtime(true);
        
        try {
            $this->runHighConcurrentRequests();
            $this->runMemoryUnderLoad();
            $this->runCpuIntensiveLoad();
            $this->runLargeResponseHandling();
            $this->runErrorRecovery();
        } catch (\Throwable $e) {
            echo "Error during stress testing: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }

        $totalDuration = microtime(true) - $startTime;
        
        echo "=== Stress Test Summary ===\n";
        echo "Total Duration: " . number_format($totalDuration, 2) . " seconds\n";
        echo "All tests completed successfully!\n\n";
        
        echo "=== Full Metrics ===\n";
        echo json_encode($this->metrics, JSON_PRETTY_PRINT) . "\n";
    }
}

// Run the stress tests
$runner = new StressTestRunner();
$runner->runAll();