<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * Interface for cacheable objects that can be monitored and cleaned by MemoryGuard
 */
interface CacheInterface
{
    /**
     * Get the current memory size of the cache in bytes
     */
    public function getMemorySize(): int;

    /**
     * Clean the cache to reduce memory usage
     *
     * @param int $targetSize Target size in bytes (hint for cleaning strategy)
     */
    public function clean(int $targetSize): void;

    /**
     * Clear all cached data
     */
    public function clear(): void;

    /**
     * Get cache statistics
     *
     * @return array{
     *   size: int,
     *   count: int,
     *   hit_rate?: float,
     *   memory_usage: int
     * }
     */
    public function getStats(): array;
}
