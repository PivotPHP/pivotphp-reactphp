<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * Runtime blocking detector using ticks
 */
final class RuntimeBlockingDetector
{
    private float $threshold = 0.1; // 100ms
    private float $lastCheck;
    private bool $enabled = false;
    /** @var callable|null */
    private $callback = null;

    public function __construct(float $threshold = 0.1)
    {
        $this->threshold = $threshold;
        $this->lastCheck = microtime(true);
    }

    /**
     * Enable runtime detection
     */
    public function enable(callable $callback): void
    {
        $this->callback = $callback;
        $this->enabled = true;
        $this->lastCheck = microtime(true);

        register_tick_function([$this, 'check']);
        declare(ticks=1000); // Check every 1000 statements
    }

    /**
     * Disable runtime detection
     */
    public function disable(): void
    {
        $this->enabled = false;
        unregister_tick_function([$this, 'check']);
    }

    /**
     * Check if code is blocking
     */
    public function check(): void
    {
        if (!$this->enabled) {
            return;
        }

        $now = microtime(true);
        $elapsed = $now - $this->lastCheck;

        if ($elapsed > $this->threshold) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            if ($this->callback !== null && is_callable($this->callback)) {
                ($this->callback)([
                    'duration' => $elapsed,
                    'file' => $backtrace[1]['file'] ?? 'unknown',
                    'line' => $backtrace[1]['line'] ?? 0,
                    'function' => $backtrace[1]['function'] ?? 'unknown',
                ]);
            }
        }

        $this->lastCheck = $now;
    }
}
