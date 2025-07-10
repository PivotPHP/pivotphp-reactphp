<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use Psr\Http\Message\ServerRequestInterface;
use PivotPHP\ReactPHP\Security\RequestIsolationInterface;

/**
 * Request Isolation Manager
 *
 * Ensures each request has its own isolated context
 * and prevents data leakage between requests
 */
final class RequestIsolation implements RequestIsolationInterface
{
    private array $globalBackup = [];
    private array $staticBackup = [];
    private array $requestContexts = [];

    /**
     * Create isolated context for a request
     */
    public function createContext(ServerRequestInterface $request): string
    {
        $contextId = $this->generateContextId($request);

        // Initialize clean context
        $this->requestContexts[$contextId] = [
            'started_at' => microtime(true),
            'globals_backup' => $this->backupGlobals(),
            'static_properties' => [],
            'memory_start' => memory_get_usage(true),
        ];

        // Reset dangerous globals
        $this->resetGlobals();

        return $contextId;
    }

    /**
     * Get context information
     */
    public function getContextInfo(string $contextId): ?array
    {
        return $this->requestContexts[$contextId] ?? null;
    }

    /**
     * Check if context exists
     */
    public function hasContext(string $contextId): bool
    {
        return isset($this->requestContexts[$contextId]);
    }

    public function getStaticBackup(): array
    {
        return $this->staticBackup;
    }

    public function getGlobalBackup(): array
    {
        return $this->globalBackup;
    }

    /**
     * Restore original state after request
     */
    public function destroyContext(string $contextId): void
    {
        if (!isset($this->requestContexts[$contextId])) {
            return;
        }

        $context = $this->requestContexts[$contextId];

        // Restore globals
        $this->restoreGlobals($context['globals_backup']);

        // Clear static properties that were modified
        $this->clearStaticProperties($context['static_properties']);

        // Force garbage collection
        unset($this->requestContexts[$contextId]);
        gc_collect_cycles();
    }

    /**
     * Track static property modification
     */
    public function trackStaticProperty(string $class, string $property, mixed $originalValue): void
    {
        $contextId = $this->getCurrentContextId();
        if ($contextId !== null) {
            $this->requestContexts[$contextId]['static_properties'][] = [
                'class' => $class,
                'property' => $property,
                'original' => $originalValue,
            ];
        }
    }

    /**
     * Get current context ID from request attribute
     */
    private function getCurrentContextId(): ?string
    {
        // This would be set in middleware
        return $_SERVER['X_REQUEST_CONTEXT_ID'] ?? null;
    }

    /**
     * Backup global state
     */
    private function backupGlobals(): array
    {
        return [
            'SERVER' => $_SERVER,
            'GET' => $_GET,
            'POST' => $_POST,
            'FILES' => $_FILES,
            'COOKIE' => $_COOKIE,
            'SESSION' => $_SESSION,
            'ENV' => $_ENV,
        ];
    }

    /**
     * Reset globals to safe defaults
     */
    private function resetGlobals(): void
    {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_REQUEST = [];

        // Keep only safe SERVER variables
        $safeServerVars = [
            'PHP_SELF', 'SCRIPT_NAME', 'argv', 'argc', 'GATEWAY_INTERFACE',
            'SERVER_ADDR', 'SERVER_NAME', 'SERVER_SOFTWARE',
            'SERVER_PROTOCOL', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT',
            'DOCUMENT_ROOT', 'SCRIPT_FILENAME',
        ];

        $preserved = [];
        foreach ($safeServerVars as $var) {
            if (isset($_SERVER[$var])) {
                $preserved[$var] = $_SERVER[$var];
            }
        }

        $_SERVER = $preserved;
    }

    /**
     * Restore globals from backup
     */
    private function restoreGlobals(array $backup): void
    {
        $_SERVER = $backup['SERVER'];
        $_GET = $backup['GET'];
        $_POST = $backup['POST'];
        $_FILES = $backup['FILES'];
        $_COOKIE = $backup['COOKIE'];
        $_SESSION = $backup['SESSION'];
        $_ENV = $backup['ENV'];
        $_REQUEST = array_merge($_GET, $_POST, $_COOKIE);
    }

    /**
     * Clear modified static properties
     */
    private function clearStaticProperties(array $properties): void
    {
        foreach ($properties as $prop) {
            try {
                $reflection = new \ReflectionClass($prop['class']);
                $property = $reflection->getProperty($prop['property']);
                $property->setAccessible(true);
                $property->setValue(null, $prop['original']);
            } catch (\Throwable $e) {
                // Log error but don't break the request
            }
        }
    }

    /**
     * Generate unique context ID for request
     */
    private function generateContextId(ServerRequestInterface $request): string
    {
        return sprintf(
            '%s_%s_%s',
            uniqid('ctx_', true),
            $request->getMethod(),
            md5($request->getUri()->getPath())
        );
    }

    /**
     * Check if context is leaked (running too long)
     */
    public function checkContextLeaks(): array
    {
        $leaks = [];
        $now = microtime(true);
        $maxDuration = 30.0; // 30 seconds max

        foreach ($this->requestContexts as $contextId => $context) {
            $duration = $now - $context['started_at'];
            if ($duration > $maxDuration) {
                $leaks[] = [
                    'context_id' => $contextId,
                    'duration' => $duration,
                    'memory_growth' => memory_get_usage(true) - $context['memory_start'],
                ];
            }
        }

        return $leaks;
    }
}
