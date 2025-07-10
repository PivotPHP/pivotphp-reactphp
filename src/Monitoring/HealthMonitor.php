<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Monitoring;

use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;

/**
 * Health Monitor
 *
 * Comprehensive monitoring and alerting system for ReactPHP server
 */
final class HealthMonitor
{
    private LoopInterface $loop;
    private LoggerInterface $logger;

    private array $metrics = [
        'requests' => [
            'total' => 0,
            'success' => 0,
            'errors' => 0,
            'active' => 0,
        ],
        'performance' => [
            'avg_response_time' => 0,
            'max_response_time' => 0,
            'min_response_time' => PHP_INT_MAX,
        ],
        'system' => [
            'uptime' => 0,
            'memory_usage' => 0,
            'cpu_usage' => 0,
            'event_loop_lag' => 0,
        ],
        'errors' => [
            'blocking_operations' => 0,
            'memory_leaks' => 0,
            'timeout_requests' => 0,
            'rate_limit_hits' => 0,
        ],
    ];

    private array $alerts = [];
    private array $alertCallbacks = [];
    private float $startTime;
    private array $responseTimes = [];

    /**
     * Alert thresholds
     */
    private array $thresholds = [
        'memory_usage_percent' => 80,
        'avg_response_time_ms' => 100,
        'error_rate_percent' => 5,
        'event_loop_lag_ms' => 50,
        'active_requests' => 1000,
    ];

    public function __construct(
        LoopInterface $loop,
        LoggerInterface $logger,
        array $thresholds = []
    ) {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->thresholds = array_merge($this->thresholds, $thresholds);
        $this->startTime = microtime(true);

        $this->startMonitoring();
    }

    /**
     * Start monitoring
     */
    private function startMonitoring(): void
    {
        // Monitor every 10 seconds
        $this->loop->addPeriodicTimer(10.0, function () {
            $this->performHealthCheck();
        });

        // Check event loop lag every second
        $this->loop->addPeriodicTimer(1.0, function () {
            $this->checkEventLoopLag();
        });

        $this->logger->info('Health monitor started');
    }

    /**
     * Register alert callback
     */
    public function onAlert(callable $callback): void
    {
        $this->alertCallbacks[] = $callback;
    }

    /**
     * Record request start
     */
    public function recordRequestStart(): string
    {
        $requestId = uniqid('req_', true);
        $this->metrics['requests']['total']++;
        $this->metrics['requests']['active']++;

        return $requestId;
    }

    /**
     * Record request end
     */
    public function recordRequestEnd(string $requestId, float $duration, bool $success = true): void
    {
        $this->metrics['requests']['active']--;

        if ($success) {
            $this->metrics['requests']['success']++;
        } else {
            $this->metrics['requests']['errors']++;
        }

        // Track response times
        $this->responseTimes[] = $duration;
        if (count($this->responseTimes) > 1000) {
            array_shift($this->responseTimes);
        }

        // Update performance metrics
        $this->updatePerformanceMetrics($duration);
    }

    /**
     * Record error
     */
    public function recordError(string $type, array $details = []): void
    {
        switch ($type) {
            case 'blocking_operation':
                $this->metrics['errors']['blocking_operations']++;
                break;
            case 'memory_leak':
                $this->metrics['errors']['memory_leaks']++;
                break;
            case 'timeout':
                $this->metrics['errors']['timeout_requests']++;
                break;
            case 'rate_limit':
                $this->metrics['errors']['rate_limit_hits']++;
                break;
        }

        $this->logger->warning("Error recorded: $type", $details);
    }

    /**
     * Perform health check
     */
    private function performHealthCheck(): void
    {
        $this->updateSystemMetrics();
        $alerts = $this->checkThresholds();

        if ($alerts !== []) {
            $this->handleAlerts($alerts);
        }

        // Log current status
        $this->logger->debug('Health check completed', [
            'metrics' => $this->metrics,
            'alerts' => count($alerts),
        ]);
    }

    /**
     * Update system metrics
     */
    private function updateSystemMetrics(): void
    {
        $this->metrics['system']['uptime'] = microtime(true) - $this->startTime;
        $this->metrics['system']['memory_usage'] = memory_get_usage(true);
        $this->metrics['system']['cpu_usage'] = $this->getCpuUsage();
    }

    /**
     * Update performance metrics
     */
    private function updatePerformanceMetrics(float $duration): void
    {
        $this->metrics['performance']['max_response_time'] = max(
            $this->metrics['performance']['max_response_time'],
            $duration
        );

        $this->metrics['performance']['min_response_time'] = min(
            $this->metrics['performance']['min_response_time'],
            $duration
        );

        if ($this->responseTimes !== []) {
            $this->metrics['performance']['avg_response_time'] =
                array_sum($this->responseTimes) / count($this->responseTimes);
        }
    }

    /**
     * Check event loop lag
     */
    private function checkEventLoopLag(): void
    {
        static $lastCheck = null;

        if ($lastCheck === null) {
            $lastCheck = microtime(true);
            return;
        }

        $now = microtime(true);
        $expectedInterval = 1.0;
        $actualInterval = $now - $lastCheck;
        $lag = ($actualInterval - $expectedInterval) * 1000; // Convert to ms

        if ($lag > 0) {
            $this->metrics['system']['event_loop_lag'] = $lag;
        }

        $lastCheck = $now;
    }

    /**
     * Check thresholds
     */
    private function checkThresholds(): array
    {
        $alerts = [];

        // Memory usage
        $memoryLimit = $this->getMemoryLimit();
        $memoryUsagePercent = ($this->metrics['system']['memory_usage'] / $memoryLimit) * 100;
        if ($memoryUsagePercent > $this->thresholds['memory_usage_percent']) {
            $alerts[] = [
                'type' => 'memory_usage',
                'severity' => 'warning',
                'message' => sprintf('Memory usage at %.1f%%', $memoryUsagePercent),
                'value' => $memoryUsagePercent,
                'threshold' => $this->thresholds['memory_usage_percent'],
            ];
        }

        // Response time
        $avgResponseTime = $this->metrics['performance']['avg_response_time'] * 1000; // Convert to ms
        if ($avgResponseTime > $this->thresholds['avg_response_time_ms']) {
            $alerts[] = [
                'type' => 'response_time',
                'severity' => 'warning',
                'message' => sprintf('Average response time %.1fms', $avgResponseTime),
                'value' => $avgResponseTime,
                'threshold' => $this->thresholds['avg_response_time_ms'],
            ];
        }

        // Error rate
        $totalRequests = $this->metrics['requests']['total'];
        if ($totalRequests > 0) {
            $errorRate = ($this->metrics['requests']['errors'] / $totalRequests) * 100;
            if ($errorRate > $this->thresholds['error_rate_percent']) {
                $alerts[] = [
                    'type' => 'error_rate',
                    'severity' => 'error',
                    'message' => sprintf('Error rate at %.1f%%', $errorRate),
                    'value' => $errorRate,
                    'threshold' => $this->thresholds['error_rate_percent'],
                ];
            }
        }

        // Event loop lag
        if ($this->metrics['system']['event_loop_lag'] > $this->thresholds['event_loop_lag_ms']) {
            $alerts[] = [
                'type' => 'event_loop_lag',
                'severity' => 'critical',
                'message' => sprintf('Event loop lag %.1fms', $this->metrics['system']['event_loop_lag']),
                'value' => $this->metrics['system']['event_loop_lag'],
                'threshold' => $this->thresholds['event_loop_lag_ms'],
            ];
        }

        // Active requests
        if ($this->metrics['requests']['active'] > $this->thresholds['active_requests']) {
            $alerts[] = [
                'type' => 'active_requests',
                'severity' => 'warning',
                'message' => sprintf('%d active requests', $this->metrics['requests']['active']),
                'value' => $this->metrics['requests']['active'],
                'threshold' => $this->thresholds['active_requests'],
            ];
        }

        return $alerts;
    }

    /**
     * Handle alerts
     */
    private function handleAlerts(array $alerts): void
    {
        foreach ($alerts as $alert) {
            // Log alert
            $logMethod = match ($alert['severity']) {
                'critical' => 'critical',
                'error' => 'error',
                'warning' => 'warning',
                default => 'info',
            };

            match ($logMethod) {
                'critical' => $this->logger->critical('Health alert: ' . $alert['message'], $alert),
                'error' => $this->logger->error('Health alert: ' . $alert['message'], $alert),
                'warning' => $this->logger->warning('Health alert: ' . $alert['message'], $alert),
                default => $this->logger->info('Health alert: ' . $alert['message'], $alert)
            };

            // Store alert
            $this->alerts[] = array_merge($alert, [
                'timestamp' => time(),
            ]);

            // Keep only last 100 alerts
            if (count($this->alerts) > 100) {
                array_shift($this->alerts);
            }

            // Notify callbacks
            foreach ($this->alertCallbacks as $callback) {
                try {
                    $callback($alert);
                } catch (\Throwable $e) {
                    $this->logger->error('Alert callback failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Get CPU usage (Linux only)
     */
    private function getCpuUsage(): float
    {
        static $lastCpu = null;
        static $lastTime = null;

        if (!file_exists('/proc/stat')) {
            return 0.0; // Not on Linux
        }

        $stat = file_get_contents('/proc/stat');
        if ($stat === false) {
            return 0.0;
        }
        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $matches);

        if (count($matches) < 5) {
            return 0.0;
        }

        $currentCpu = (int) $matches[1] + (int) $matches[2] + (int) $matches[3];
        $currentTime = microtime(true);

        if ($lastCpu !== null && $lastTime !== null) {
            $cpuDiff = $currentCpu - $lastCpu;
            $timeDiff = $currentTime - $lastTime;

            $usage = ($cpuDiff / $timeDiff) / 100; // Normalize to percentage
            $lastCpu = $currentCpu;
            $lastTime = $currentTime;

            return min(100, max(0, $usage));
        }

        $lastCpu = $currentCpu;
        $lastTime = $currentTime;

        return 0.0;
    }

    /**
     * Get memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        return $this->parseBytes($limit);
    }

    /**
     * Parse bytes from string
     */
    private function parseBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get health status
     */
    public function getHealthStatus(): array
    {
        $status = 'healthy';
        $issues = [];

        foreach ($this->alerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $status = 'critical';
            } elseif ($alert['severity'] === 'error' && $status !== 'critical') {
                $status = 'unhealthy';
            } elseif ($alert['severity'] === 'warning' && $status === 'healthy') {
                $status = 'degraded';
            }

            $issues[] = $alert['message'];
        }

        return [
            'status' => $status,
            'uptime' => $this->formatUptime($this->metrics['system']['uptime']),
            'metrics' => $this->metrics,
            'issues' => array_unique($issues),
            'last_check' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Format uptime
     */
    private function formatUptime(float $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);

        if ($days > 0) {
            return sprintf('%dd %dh %dm %ds', $days, $hours, $minutes, $secs);
        } elseif ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }
}
