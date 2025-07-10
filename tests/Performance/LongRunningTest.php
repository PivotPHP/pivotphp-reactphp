<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Performance;

use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Response;
use PivotPHP\Core\Routing\Router;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Security\MemoryGuard;
use PivotPHP\ReactPHP\Security\GlobalStateSandbox;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\ReactPHP\Tests\Helpers\JsonHelper;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;

final class LongRunningTest extends TestCase
{
    protected Application $app;
    private ReactServer $server;
    private MemoryGuard $memoryGuard;
    private GlobalStateSandbox $sandbox;
    private array $performanceMetrics = [];

    public function getMemoryGuard(): MemoryGuard
    {
        return $this->memoryGuard;
    }

    public function getSandbox(): GlobalStateSandbox
    {
        return $this->sandbox;
    }

    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(__DIR__);

        // Setup monitoring
        $this->memoryGuard = new MemoryGuard(
            Loop::get(),
            [
                'max_memory' => 256 * 1024 * 1024, // 256MB
                'warning_threshold' => 200 * 1024 * 1024, // 200MB
                'check_interval' => 5, // Check every 5 seconds
            ]
        );

        $this->sandbox = new GlobalStateSandbox();

        $this->server = new ReactServer($this->app, Loop::get());

        $this->setupLongRunningRoutes();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function setupLongRunningRoutes(): void
    {
        $router = $this->app->make(Router::class);
        assert($router instanceof Router);

        // State accumulation test
        $router::get('/state-test', function () {
            static $requestCount = 0;
            $requestCount++;

            return (new Response())->json([
                'request_count' => $requestCount,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ]);
        });

        // Cache accumulation test
        $router::get('/cache-test', function () {
            static $cache = [];

            // Add to cache
            $key = 'item_' . count($cache);
            $cache[$key] = str_repeat('x', 1024); // 1KB per item

            // Clean old items if cache too large
            if (count($cache) > 1000) {
                $cache = array_slice($cache, -500, null, true);
            }

            return (new Response())->json([
                'cache_size' => count($cache),
                'cache_memory' => strlen(serialize($cache)),
            ]);
        });

        // Global state pollution test
        $router::post('/global-test', function ($request) {
            $body = JsonHelper::decode((string) $request->getBody());

            // Intentionally pollute globals
            $GLOBALS['test_data'] = $body['data'] ?? 'default';
            $_SESSION['user_data'] = $body['user'] ?? null;

            return (new Response())->json([
                'globals_set' => true,
                'test_data' => $GLOBALS['test_data'] ?? null,
                'session_data' => $_SESSION['user_data'] ?? null,
            ]);
        });

        // Resource leak simulation
        $router::get('/resource-leak', function () {
            static $resources = [];

            // Create a temporary file (resource)
            $temp = tmpfile();
            $resources[] = $temp;

            // Write some data
            fwrite($temp, str_repeat('x', 10000));

            return (new Response())->json([
                'open_resources' => count($resources),
                'memory_usage' => memory_get_usage(true),
            ]);
        });
    }

    /**
     * @group long-running
     */
    public function testMemoryStabilityOverTime(): void
    {
        self::markTestSkipped('Long-running tests should be run manually');
    }

    /**
     * @group long-running
     */
    public function testGlobalStateIsolation(): void
    {
        self::markTestSkipped('Long-running tests should be run manually');
    }
}
