<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * Example implementation of CacheInterface using an array for storage
 *
 * This is a proper implementation that can be effectively monitored and cleaned
 * by MemoryGuard, unlike plain arrays.
 */
final class ArrayCache implements CacheInterface
{
    private array $data = [];
    private int $hits = 0;
    private int $misses = 0;

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->data)) {
            $this->hits++;
            return $this->data[$key];
        }

        $this->misses++;
        return null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): bool
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            return true;
        }

        return false;
    }

    public function getMemorySize(): int
    {
        try {
            return strlen(serialize($this->data));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function clean(int $targetSize): void
    {
        $currentSize = $this->getMemorySize();

        if ($currentSize <= $targetSize) {
            return;
        }

        // Remove oldest entries (assuming insertion order)
        $removeCount = (int) (count($this->data) * 0.25); // Remove 25%

        if ($removeCount > 0) {
            $keys = array_keys($this->data);
            for ($i = 0; $i < $removeCount; $i++) {
                if (isset($keys[$i])) {
                    unset($this->data[$keys[$i]]);
                }
            }
        }
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? ($this->hits / $total) : 0.0;

        return [
            'size' => $this->getMemorySize(),
            'count' => count($this->data),
            'hit_rate' => $hitRate,
            'memory_usage' => $this->getMemorySize(),
            'hits' => $this->hits,
            'misses' => $this->misses,
        ];
    }
}
