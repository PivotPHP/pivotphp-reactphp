<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * Wrapper that makes existing cache objects compatible with CacheInterface
 */
final class CacheWrapper implements CacheInterface
{
    public function __construct(
        private mixed $cache,
        private string $type
    ) {
    }

    public function getMemorySize(): int
    {
        switch ($this->type) {
            case 'ArrayObject':
                if ($this->cache instanceof \ArrayObject) {
                    try {
                        return strlen(serialize($this->cache->getArrayCopy()));
                    } catch (\Throwable) {
                        return 0;
                    }
                }
                break;

            case 'SplObjectStorage':
                if ($this->cache instanceof \SplObjectStorage) {
                    try {
                        return strlen(serialize(iterator_to_array($this->cache)));
                    } catch (\Throwable) {
                        return 0;
                    }
                }
                break;

            case 'countable':
                if (is_object($this->cache)) {
                    try {
                        return strlen(serialize($this->cache));
                    } catch (\Throwable) {
                        return 0;
                    }
                }
                break;
        }

        return 0;
    }

    public function clean(int $targetSize): void
    {
        switch ($this->type) {
            case 'ArrayObject':
                if ($this->cache instanceof \ArrayObject) {
                    $this->cleanArrayObject($targetSize);
                }
                break;

            case 'SplObjectStorage':
                if ($this->cache instanceof \SplObjectStorage) {
                    $this->cleanSplObjectStorage($targetSize);
                }
                break;

            case 'countable':
                if (is_object($this->cache) && method_exists($this->cache, 'clear')) {
                    // For objects with clear() method, just clear completely
                    $this->cache->clear();
                } elseif (is_object($this->cache) && method_exists($this->cache, 'flush')) {
                    $this->cache->flush();
                }
                break;
        }
    }

    public function clear(): void
    {
        switch ($this->type) {
            case 'ArrayObject':
                if ($this->cache instanceof \ArrayObject) {
                    $this->cache->exchangeArray([]);
                }
                break;

            case 'SplObjectStorage':
                if ($this->cache instanceof \SplObjectStorage) {
                    $this->cache->removeAll($this->cache);
                }
                break;

            case 'countable':
                if (is_object($this->cache) && method_exists($this->cache, 'clear')) {
                    $this->cache->clear();
                } elseif (is_object($this->cache) && method_exists($this->cache, 'flush')) {
                    $this->cache->flush();
                }
                break;
        }
    }

    public function getStats(): array
    {
        $count = 0;
        $memoryUsage = $this->getMemorySize();

        switch ($this->type) {
            case 'ArrayObject':
                if ($this->cache instanceof \ArrayObject) {
                    $count = $this->cache->count();
                }
                break;

            case 'SplObjectStorage':
                if ($this->cache instanceof \SplObjectStorage) {
                    $count = $this->cache->count();
                }
                break;

            case 'countable':
                if (is_object($this->cache) && method_exists($this->cache, 'count')) {
                    $count = $this->cache->count();
                }
                break;
        }

        return [
            'size' => $memoryUsage,
            'count' => $count,
            'memory_usage' => $memoryUsage,
        ];
    }

    /**
     * Get the underlying cache object
     */
    public function getCache(): mixed
    {
        return $this->cache;
    }

    /**
     * Get the cache type
     */
    public function getType(): string
    {
        return $this->type;
    }

    private function cleanArrayObject(int $targetSize): void
    {
        if (!$this->cache instanceof \ArrayObject) {
            return;
        }

        $array = $this->cache->getArrayCopy();
        $currentSize = strlen(serialize($array));

        if ($currentSize <= $targetSize) {
            return;
        }

        // Remove 25% of items (oldest first if keys are ordered)
        $removeCount = (int) (count($array) * 0.25);
        if ($removeCount > 0) {
            $array = array_slice($array, $removeCount, null, true);
            $this->cache->exchangeArray($array);
        }
    }

    private function cleanSplObjectStorage(int $targetSize): void
    {
        if (!$this->cache instanceof \SplObjectStorage) {
            return;
        }

        $all = iterator_to_array($this->cache);
        $currentSize = strlen(serialize($all));

        if ($currentSize <= $targetSize) {
            return;
        }

        // Remove 25% of objects
        $removeCount = (int) (count($all) * 0.25);
        if ($removeCount > 0) {
            for ($i = 0; $i < $removeCount; $i++) {
                $object = array_shift($all);
                if ($object !== null) {
                    $this->cache->detach($object);
                }
            }
        }
    }
}
