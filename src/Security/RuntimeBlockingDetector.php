<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Runtime blocking detector using ReactPHP timer-based sampling
 *
 * This implementation replaces tick functions with timer-based detection
 * to avoid the performance overhead of tick functions, which are called
 * very frequently and can significantly impact performance.
 */
final class RuntimeBlockingDetector
{
    private float $threshold = 0.1; // 100ms
    private float $samplingInterval = 0.01; // 10ms sampling interval
    private float $lastActivity;
    private bool $enabled = false;
    /** @var callable|null */
    private $callback = null;
    private ?LoopInterface $loop = null;
    private ?TimerInterface $timer = null;
    private int $consecutiveBlockingCount = 0;
    private int $maxConsecutiveBlocking = 5;

    public function __construct(float $threshold = 0.1, float $samplingInterval = 0.01)
    {
        $this->threshold = $threshold;
        $this->samplingInterval = $samplingInterval;
        $this->lastActivity = microtime(true);
    }

    /**
     * Enable runtime detection with timer-based sampling
     */
    public function enable(callable $callback, ?LoopInterface $loop = null): void
    {
        $this->callback = $callback;
        $this->enabled = true;
        $this->lastActivity = microtime(true);
        $this->consecutiveBlockingCount = 0;

        // Use provided loop or get default loop
        $this->loop = $loop ?? \React\EventLoop\Loop::get();

        // Start periodic timer for sampling
        $this->timer = $this->loop->addPeriodicTimer($this->samplingInterval, [$this, 'sample']);
    }

    /**
     * Disable runtime detection
     */
    public function disable(): void
    {
        $this->enabled = false;

        if ($this->timer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->loop = null;
        $this->consecutiveBlockingCount = 0;
    }

    /**
     * Record activity timestamp (should be called by monitored code)
     */
    public function recordActivity(): void
    {
        if ($this->enabled) {
            $this->lastActivity = microtime(true);
            $this->consecutiveBlockingCount = 0;
        }
    }

    /**
     * Sample the current state to detect blocking
     */
    public function sample(): void
    {
        if (!$this->enabled) {
            return;
        }

        $now = microtime(true);
        $elapsed = $now - $this->lastActivity;

        if ($elapsed > $this->threshold) {
            $this->consecutiveBlockingCount++;

            // Only report after consecutive blocking detections to avoid false positives
            if ($this->consecutiveBlockingCount >= $this->maxConsecutiveBlocking) {
                $this->reportBlocking($elapsed);
                $this->consecutiveBlockingCount = 0; // Reset counter after reporting
            }
        } else {
            $this->consecutiveBlockingCount = 0;
        }
    }

    /**
     * Report blocking behavior
     */
    private function reportBlocking(float $duration): void
    {
        if ($this->callback !== null && is_callable($this->callback)) {
            // Get stack trace with more context for blocked operations
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

            // Filter out our own methods from the stack trace
            $filteredTrace = array_filter($backtrace, function ($frame) {
                return !isset($frame['class']) || $frame['class'] !== self::class;
            });

            $relevantFrame = reset($filteredTrace) !== false
                ? reset($filteredTrace)
                : ['file' => 'unknown', 'line' => 0, 'function' => 'unknown'];

            ($this->callback)([
                'duration' => $duration,
                'file' => $relevantFrame['file'] ?? 'unknown',
                'line' => $relevantFrame['line'] ?? 0,
                'function' => $relevantFrame['function'] ?? 'unknown',
                'sampling_interval' => $this->samplingInterval,
                'consecutive_blocks' => $this->consecutiveBlockingCount,
            ]);
        }
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return [
            'threshold' => $this->threshold,
            'sampling_interval' => $this->samplingInterval,
            'enabled' => $this->enabled,
            'consecutive_blocking_count' => $this->consecutiveBlockingCount,
        ];
    }

    /**
     * Set the maximum number of consecutive blocking detections before reporting
     */
    public function setMaxConsecutiveBlocking(int $max): void
    {
        $this->maxConsecutiveBlocking = max(1, $max);
    }

    /**
     * Create a wrapper function that automatically records activity
     */
    public function wrapFunction(callable $func): callable
    {
        return function (...$args) use ($func) {
            $this->recordActivity();
            $result = $func(...$args);
            $this->recordActivity();
            return $result;
        };
    }
}
