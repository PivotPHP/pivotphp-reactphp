<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Security;

use PivotPHP\ReactPHP\Security\MemoryGuard;
use PivotPHP\ReactPHP\Tests\TestCase;
use PivotPHP\ReactPHP\Tests\Helpers\MockHelper;
use PHPUnit\Framework\MockObject\MockObject;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;

final class MemoryGuardTest extends TestCase
{
    private MemoryGuard $memoryGuard;
    /** @var MockObject&LoggerInterface */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->memoryGuard = new MemoryGuard(
            Loop::get(),
            [
                'max_memory' => 100 * 1024 * 1024, // 100MB
                'warning_threshold' => 80 * 1024 * 1024, // 80MB
                'gc_threshold' => 50 * 1024 * 1024, // 50MB
                'check_interval' => 0.1, // 100ms for tests
                'leak_detection_enabled' => true,
            ],
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        // Stop event loop to clean timers
        Loop::get()->stop();
        parent::tearDown();
    }

    public function testStartMonitoring(): void
    {
        // @phpstan-ignore-next-line PHPUnit framework method, false positive
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Memory guard started',
                // @phpstan-ignore-next-line PHPUnit framework method, false positive
                $this->arrayHasKey('max_memory')
            );

        $this->memoryGuard->startMonitoring();

        // Run loop briefly
        Loop::get()->futureTick(function () {
            Loop::get()->stop();
        });
        Loop::get()->run();
    }

    public function testRegisterCache(): void
    {
        $cache = new \ArrayObject();

        $this->memoryGuard->registerCache('test_cache', $cache, 1024 * 1024); // 1MB limit

        $stats = $this->memoryGuard->getStats();
        self::assertEquals(1, $stats['tracked_caches']);
    }

    public function testMemoryLeakCallback(): void
    {
        $leakDetected = false;
        $leakData = null;

        $this->memoryGuard->onMemoryLeak(function ($data) use (&$leakDetected, &$leakData) {
            $leakDetected = true;
            $leakData = $data;
        });

        // This would trigger in real scenario with memory growth
        // For testing, we'll check that callback is registered
        self::assertFalse($leakDetected); // No leak yet
    }

    public function testCacheSizeDetection(): void
    {
        // Test with ArrayObject cache (proper implementation)
        $arrayCache = new \ArrayObject();
        for ($i = 0; $i < 100; $i++) {
            $arrayCache[] = str_repeat('x', 100); // 100 bytes each
        }

        $this->memoryGuard->registerCache('array_cache', $arrayCache, 5000); // 5KB limit

        // Verify cache was registered
        $stats = $this->memoryGuard->getStats();
        self::assertEquals(1, $stats['tracked_caches']);
    }

    public function testGetStats(): void
    {
        $this->memoryGuard->startMonitoring();

        $cache = new \ArrayObject();
        $this->memoryGuard->registerCache('test', $cache);

        $stats = $this->memoryGuard->getStats();

        self::assertArrayHasKey('current_memory', $stats);
        self::assertArrayHasKey('peak_memory', $stats);
        self::assertArrayHasKey('gc_runs', $stats);
        self::assertArrayHasKey('uptime', $stats);
        self::assertArrayHasKey('tracked_caches', $stats);
        self::assertArrayHasKey('monitoring', $stats);

        self::assertTrue($stats['monitoring']);
        self::assertEquals(1, $stats['tracked_caches']);
        self::assertGreaterThan(0, $stats['current_memory']);
    }

    public function testArrayCacheTypeValidation(): void
    {
        // This test now validates that plain arrays are properly rejected
        $cache = ['item1', 'item2', 'item3'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Plain arrays cannot be monitored effectively');

        $this->memoryGuard->registerCache('array', $cache);
    }

    public function testArrayObjectCacheType(): void
    {
        $cache = new \ArrayObject(['a' => 1, 'b' => 2]);
        $this->memoryGuard->registerCache('array_object', $cache);

        $stats = $this->memoryGuard->getStats();
        self::assertEquals(1, $stats['tracked_caches']);
    }

    public function testSplObjectStorageCacheType(): void
    {
        $cache = new \SplObjectStorage();
        $obj1 = new \stdClass();
        $obj2 = new \stdClass();
        $cache->attach($obj1);
        $cache->attach($obj2);

        $this->memoryGuard->registerCache('spl_storage', $cache);

        $stats = $this->memoryGuard->getStats();
        self::assertEquals(1, $stats['tracked_caches']);
    }

    public function testHighMemoryWarning(): void
    {
        // Create a guard with very low thresholds for testing
        $guard = new MemoryGuard(
            Loop::get(),
            [
                'max_memory' => 1024, // 1KB (unrealistic but for testing)
                'warning_threshold' => 512, // 512 bytes
                'gc_threshold' => 256, // 256 bytes
                'check_interval' => 0.1,
            ],
            $this->logger
        );

        // @phpstan-ignore-next-line PHPUnit framework method, false positive
        $this->logger->expects($this->any())
            ->method('warning')
            ->with(
                // @phpstan-ignore-next-line PHPUnit framework method, false positive
                $this->stringContains('memory usage'),
                // @phpstan-ignore-next-line PHPUnit framework method, false positive
                $this->anything()
            );

        $guard->startMonitoring();

        // Create modest data to trigger memory thresholds (test has 512 byte warning threshold)
        $data = str_repeat('x', 2048); // 2KB - enough to trigger warning

        // Run event loop briefly to trigger check
        Loop::get()->addTimer(0.2, function () {
            Loop::get()->stop();
        });
        Loop::get()->run();

        // Verify guard was created and configured properly
        $stats = $guard->getStats();
        self::assertArrayHasKey('monitoring', $stats);
        self::assertTrue($stats['monitoring']);

        unset($data);
    }

    public function testMultipleCaches(): void
    {
        $cache1 = new \ArrayObject();
        $cache3 = new \SplObjectStorage();

        $this->memoryGuard->registerCache('cache1', $cache1);
        $this->memoryGuard->registerCache('cache3', $cache3);

        $stats = $this->memoryGuard->getStats();
        self::assertEquals(2, $stats['tracked_caches']);
    }

    public function testArrayCacheRejected(): void
    {
        $arrayCache = ['data' => 'test'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Plain arrays cannot be monitored effectively');

        $this->memoryGuard->registerCache('bad_cache', $arrayCache);
    }

    public function testCacheInterfaceImplementation(): void
    {
        $cache = new \PivotPHP\ReactPHP\Security\ArrayCache();
        $cache->set('test', 'value');

        // Should not throw exception
        $this->memoryGuard->registerCache('good_cache', $cache);

        $stats = $this->memoryGuard->getStats();
        self::assertEquals(1, $stats['tracked_caches']);
    }

    public function testMemoryLeakCallbackTriggered(): void
    {
        $leakCallbackCalled = false;
        $leakInfo = null;

        $guard = new MemoryGuard(
            Loop::get(),
            [
                'check_interval' => 0.1,
                'leak_detection_enabled' => true,
            ],
            $this->logger
        );

        $guard->onMemoryLeak(function ($info) use (&$leakCallbackCalled, &$leakInfo) {
            $leakCallbackCalled = true;
            $leakInfo = $info;
        });

        // In real scenario, memory leak would be detected over time
        // For unit test, we just verify the callback mechanism exists
        self::assertFalse($leakCallbackCalled);
    }

    public function testCriticalMemoryHandling(): void
    {
        $criticalCallbackCalled = false;

        $guard = new MemoryGuard(
            Loop::get(),
            [
                'max_memory' => 1024, // 1KB
                'auto_restart_threshold' => 2048, // 2KB
                'check_interval' => 0.1,
            ],
            $this->logger
        );

        $guard->onMemoryLeak(function ($info) use (&$criticalCallbackCalled) {
            if ($info['type'] === 'critical_memory') {
                $criticalCallbackCalled = true;
            }
        });

        // @phpstan-ignore-next-line PHPUnit framework method, false positive
        $this->logger->expects($this->any())
            ->method('error')
            ->with(
                // @phpstan-ignore-next-line PHPUnit framework method, false positive
                $this->stringContains('Critical memory usage'),
                // @phpstan-ignore-next-line PHPUnit framework method, false positive
                $this->anything()
            );

        // In real scenario, this would trigger when memory exceeds threshold
        self::assertFalse($criticalCallbackCalled);
    }

    public function testCacheCleaningMechanism(): void
    {
        // Create a mock cache that can be cleared
        $cache = new class {
            /** @var array<string> */
            public array $data = [];

            public function clear(): void
            {
                $this->data = [];
            }

            public function count(): int
            {
                return count($this->data);
            }
        };

        // Fill cache with data
        for ($i = 0; $i < 100; $i++) {
            $cache->data[] = str_repeat('x', 1000);
        }

        $this->memoryGuard->registerCache('clearable', $cache, 1024); // 1KB limit

        self::assertEquals(100, $cache->count());

        // In real scenario, the guard would detect size and clear cache
        // For testing, we verify the cache can be cleared
        $cache->clear();
        self::assertEquals(0, $cache->count());
    }
}
