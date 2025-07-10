<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Memory Guard
 *
 * Automatic memory management to prevent leaks and excessive usage
 */
final class MemoryGuard
{
    private LoopInterface $loop;
    private LoggerInterface $logger;

    /**
     * Memory thresholds and limits
     */
    private array $config = [
        'max_memory' => 256 * 1024 * 1024, // 256MB max
        'warning_threshold' => 200 * 1024 * 1024, // 200MB warning
        'gc_threshold' => 100 * 1024 * 1024, // 100MB trigger GC
        'check_interval' => 10.0, // Check every 10 seconds
        'leak_detection_enabled' => true,
        'auto_restart_threshold' => 300 * 1024 * 1024, // 300MB force restart
        'cache_size_limits' => [
            'default' => 10 * 1024 * 1024, // 10MB per cache
        ],
    ];

    private array $memorySnapshots = [];
    private array $trackedCaches = [];
    private array $leakCallbacks = [];
    private ?float $startTime = null;
    private int $gcRuns = 0;
    private bool $monitoring = false;

    public function __construct(LoopInterface $loop, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->loop = $loop;
        $this->config = array_merge($this->config, $config);
        $this->logger = $logger ?? new NullLogger();
        $this->startTime = microtime(true);
    }

    /**
     * Start memory monitoring
     */
    public function startMonitoring(): void
    {
        if ($this->monitoring) {
            return;
        }

        $this->monitoring = true;

        // Periodic memory check
        $this->loop->addPeriodicTimer($this->config['check_interval'], function () {
            $this->performMemoryCheck();
        });

        // More frequent cache size check
        $this->loop->addPeriodicTimer(2.0, function () {
            $this->checkCacheSizes();
        });

        $this->logger->info('Memory guard started', [
            'max_memory' => $this->formatBytes($this->config['max_memory']),
            'check_interval' => $this->config['check_interval'],
        ]);
    }

    /**
     * Register a cache to monitor
     */
    public function registerCache(string $name, mixed $cache, ?int $maxSize = null): void
    {
        $this->trackedCaches[$name] = [
            'object' => $cache,
            'max_size' => $maxSize ?? $this->config['cache_size_limits']['default'],
            'type' => $this->detectCacheType($cache),
        ];
    }

    /**
     * Register leak detection callback
     */
    public function onMemoryLeak(callable $callback): void
    {
        $this->leakCallbacks[] = $callback;
    }

    /**
     * Perform memory check
     */
    private function performMemoryCheck(): void
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        // Take snapshot
        $snapshot = [
            'time' => microtime(true),
            'current' => $current,
            'peak' => $peak,
            'gc_runs' => gc_collect_cycles(),
        ];

        $this->memorySnapshots[] = $snapshot;

        // Keep only last 60 snapshots (10 minutes worth)
        if (count($this->memorySnapshots) > 60) {
            array_shift($this->memorySnapshots);
        }

        // Check thresholds
        if ($current > $this->config['auto_restart_threshold']) {
            $this->handleCriticalMemory($current);
        } elseif ($current > $this->config['warning_threshold']) {
            $this->handleHighMemory($current);
        } elseif ($current > $this->config['gc_threshold']) {
            $this->triggerGarbageCollection();
        }

        // Detect leaks
        if ($this->config['leak_detection_enabled']) {
            $this->detectMemoryLeaks();
        }
    }

    /**
     * Check cache sizes and clean if necessary
     */
    private function checkCacheSizes(): void
    {
        foreach ($this->trackedCaches as $name => $info) {
            $cache = $info['object'];
            $maxSize = $info['max_size'];

            $currentSize = $this->getCacheSize($cache, $info['type']);

            if ($currentSize > $maxSize) {
                $this->logger->warning('Cache size exceeded', [
                    'cache' => $name,
                    'current_size' => $this->formatBytes($currentSize),
                    'max_size' => $this->formatBytes($maxSize),
                ]);

                $this->cleanCache($cache, $info['type'], $maxSize);
            }
        }
    }

    /**
     * Detect cache type
     */
    private function detectCacheType(mixed $cache): string
    {
        if (is_array($cache)) {
            return 'array';
        } elseif ($cache instanceof \ArrayObject) {
            return 'ArrayObject';
        } elseif ($cache instanceof \SplObjectStorage) {
            return 'SplObjectStorage';
        } elseif (is_object($cache) && method_exists($cache, 'count') && method_exists($cache, 'clear')) {
            return 'countable';
        } else {
            return 'unknown';
        }
    }

    /**
     * Get cache size in bytes
     */
    private function getCacheSize(mixed $cache, string $type): int
    {
        $size = 0;

        switch ($type) {
            case 'array':
                if (is_array($cache)) {
                    foreach ($cache as $item) {
                        $size += strlen(serialize($item));
                    }
                }
                break;

            case 'ArrayObject':
            case 'countable':
                if (is_object($cache) && method_exists($cache, 'count')) {
                    // Estimate based on count
                    $count = $cache->count();
                    $size = $count * 1024; // Assume 1KB average per item
                }
                break;

            case 'SplObjectStorage':
                if (is_object($cache) && method_exists($cache, 'count')) {
                    $size = $cache->count() * 2048; // Assume 2KB per object+data
                }
                break;

            default:
                // Try to serialize and measure
                try {
                    $size = strlen(serialize($cache));
                } catch (\Throwable $e) {
                    $size = 0;
                }
        }

        return $size;
    }

    /**
     * Clean cache to reduce size
     */
    private function cleanCache(mixed $cache, string $type, int $targetSize): void
    {
        switch ($type) {
            case 'array':
                if (is_array($cache)) {
                    // Arrays passed by value, cannot modify directly
                    $this->logger->warning('Cannot clean array cache passed by value', [
                        'cache_type' => 'array',
                        'suggestion' => 'Use ArrayObject instead of plain arrays for mutable caches'
                    ]);
                }
                break;

            case 'countable':
                if (is_object($cache) && method_exists($cache, 'clear')) {
                    $cache->clear();
                } elseif (is_object($cache) && method_exists($cache, 'flush')) {
                    $cache->flush();
                }
                break;

            case 'SplObjectStorage':
                // Remove oldest 25% of objects
                if (is_iterable($cache)) {
                    $all = iterator_to_array($cache);
                    $removeCount = (int) (count($all) * 0.25);
                    for ($i = 0; $i < $removeCount; $i++) {
                        if (isset($all[$i]) && is_object($cache) && method_exists($cache, 'detach')) {
                            $cache->detach($all[$i]);
                        }
                    }
                }
                break;
        }

        // Force garbage collection
        gc_collect_cycles();
    }

    /**
     * Handle high memory usage
     */
    private function handleHighMemory(int $current): void
    {
        $this->logger->warning('High memory usage detected', [
            'current' => $this->formatBytes($current),
            'threshold' => $this->formatBytes($this->config['warning_threshold']),
            'uptime' => $this->getUptime(),
        ]);

        // Aggressive garbage collection
        $this->triggerGarbageCollection();

        // Clean all caches by 50%
        foreach ($this->trackedCaches as $name => $info) {
            $this->cleanCache($info['object'], $info['type'], $info['max_size'] / 2);
        }
    }

    /**
     * Handle critical memory usage
     */
    private function handleCriticalMemory(int $current): void
    {
        $this->logger->error('Critical memory usage - restart required', [
            'current' => $this->formatBytes($current),
            'threshold' => $this->formatBytes($this->config['auto_restart_threshold']),
            'uptime' => $this->getUptime(),
        ]);

        // Notify callbacks
        foreach ($this->leakCallbacks as $callback) {
            $callback([
                'type' => 'critical_memory',
                'current' => $current,
                'threshold' => $this->config['auto_restart_threshold'],
            ]);
        }

        // Clear all caches
        foreach ($this->trackedCaches as $name => $info) {
            if (is_object($info['object']) && method_exists($info['object'], 'clear')) {
                $info['object']->clear();
            }
        }

        // Final GC attempt
        gc_collect_cycles();

        // Schedule graceful restart
        $this->loop->addTimer(1.0, function () {
            $this->logger->emergency('Initiating graceful restart due to memory limit');
            // This would trigger a graceful shutdown in production
            // For now, just log it
        });
    }

    /**
     * Trigger garbage collection
     */
    private function triggerGarbageCollection(): void
    {
        $before = memory_get_usage(true);
        $cycles = gc_collect_cycles();
        $after = memory_get_usage(true);

        $freed = $before - $after;
        $this->gcRuns++;

        if ($freed > 1024 * 1024) { // Log if more than 1MB freed
            $this->logger->info('Garbage collection completed', [
                'cycles' => $cycles,
                'freed' => $this->formatBytes($freed),
                'total_runs' => $this->gcRuns,
            ]);
        }
    }

    /**
     * Detect memory leaks
     */
    private function detectMemoryLeaks(): void
    {
        if (count($this->memorySnapshots) < 6) {
            return; // Need at least 1 minute of data
        }

        // Calculate growth rate
        $first = reset($this->memorySnapshots);
        $last = end($this->memorySnapshots);

        $timeElapsed = $last['time'] - $first['time'];
        $memoryGrowth = $last['current'] - $first['current'];
        $growthRate = $memoryGrowth / $timeElapsed; // Bytes per second

        // If growing more than 1MB per minute
        if ($growthRate > (1024 * 1024 / 60)) {
            $this->logger->warning('Potential memory leak detected', [
                'growth_rate' => $this->formatBytes((int) ($growthRate * 60)) . '/min',
                'total_growth' => $this->formatBytes($memoryGrowth),
                'time_elapsed' => round($timeElapsed) . 's',
            ]);

            // Notify callbacks
            foreach ($this->leakCallbacks as $callback) {
                $callback([
                    'type' => 'memory_leak',
                    'growth_rate' => $growthRate,
                    'snapshots' => $this->memorySnapshots,
                ]);
            }
        }
    }

    /**
     * Get uptime in human readable format
     */
    private function getUptime(): string
    {
        if ($this->startTime === null) {
            return 'unknown';
        }

        $seconds = (int) (microtime(true) - $this->startTime);
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes}m";
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get memory statistics
     */
    public function getStats(): array
    {
        return [
            'current_memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'gc_runs' => $this->gcRuns,
            'uptime' => $this->getUptime(),
            'tracked_caches' => count($this->trackedCaches),
            'snapshots' => count($this->memorySnapshots),
            'monitoring' => $this->monitoring,
        ];
    }
}
