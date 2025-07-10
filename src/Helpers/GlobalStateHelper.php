<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Helpers;

/**
 * Helper for managing global state backup, restore, and isolation
 */
final class GlobalStateHelper
{
    /**
     * Backup all superglobal variables
     */
    public static function backup(): array
    {
        return [
            '_SERVER' => $_SERVER,
            '_GET' => $_GET,
            '_POST' => $_POST,
            '_COOKIE' => $_COOKIE,
            '_SESSION' => $_SESSION ?? [],
            '_FILES' => $_FILES,
            '_ENV' => $_ENV,
            'GLOBALS' => [], // Don't backup GLOBALS itself for security
        ];
    }

    /**
     * Restore superglobal variables from backup
     */
    public static function restore(array $backup): void
    {
        $_SERVER = $backup['_SERVER'];
        $_GET = $backup['_GET'];
        $_POST = $backup['_POST'];
        $_COOKIE = $backup['_COOKIE'];
        $_SESSION = $backup['_SESSION'] ?? [];
        $_FILES = $backup['_FILES'];
        $_ENV = $backup['_ENV'];
    }

    /**
     * Reset superglobals to safe defaults
     */
    public static function reset(): void
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        // Don't reset $_SESSION completely as it may be needed
        // Don't reset $_SERVER or $_ENV as they contain system info
    }

    /**
     * Get safe SERVER variables (filtering out sensitive ones)
     */
    public static function getSafeServerVars(): array
    {
        $safe = [];
        $allowed = [
            'SERVER_SOFTWARE',
            'SERVER_PROTOCOL',
            'GATEWAY_INTERFACE',
            'REQUEST_METHOD',
            'REQUEST_URI',
            'SCRIPT_NAME',
            'SCRIPT_FILENAME',
            'DOCUMENT_ROOT',
            'HTTP_HOST',
            'HTTP_USER_AGENT',
            'HTTP_ACCEPT',
            'HTTP_ACCEPT_LANGUAGE',
            'HTTP_ACCEPT_ENCODING',
            'HTTP_CONNECTION',
            'HTTPS',
            'REQUEST_TIME',
            'REQUEST_TIME_FLOAT',
        ];

        foreach ($allowed as $key) {
            // @phpstan-ignore-next-line Global superglobal always exists in PHP environment
            if (isset($_SERVER[$key])) {
                $safe[$key] = $_SERVER[$key];
            }
        }

        return $safe;
    }

    /**
     * Get safe ENV variables (filtering out sensitive ones)
     */
    public static function getSafeEnvVars(): array
    {
        $safe = [];
        $allowed = [
            'PATH',
            'HOME',
            'USER',
            'LANG',
            'LC_ALL',
            'TZ',
            'APP_ENV',
            'APP_DEBUG',
            // Add other non-sensitive env vars as needed
        ];

        foreach ($allowed as $key) {
            // @phpstan-ignore-next-line Global superglobal always exists in PHP environment
            if (isset($_ENV[$key])) {
                $safe[$key] = $_ENV[$key];
            }
        }

        return $safe;
    }

    /**
     * Create isolated superglobal context
     */
    public static function createIsolatedContext(
        array $get = [],
        array $post = [],
        array $cookie = [],
        array $files = [],
        array $serverOverrides = []
    ): array {
        $backup = self::backup();

        $_GET = $get;
        $_POST = $post;
        $_COOKIE = $cookie;
        $_FILES = $files;

        // Merge server overrides with safe defaults
        foreach ($serverOverrides as $key => $value) {
            $_SERVER[$key] = $value;
        }

        return $backup;
    }

    /**
     * Check for dangerous global access patterns
     */
    public static function detectDangerousAccess(string $code): array
    {
        $violations = [];
        $patterns = [
            '/\$GLOBALS\s*\[/' => 'Direct $GLOBALS access detected',
            '/global\s+\$/' => 'Global keyword usage detected',
            '/\$_SESSION\s*\[/' => 'Direct $_SESSION access detected',
            '/putenv\s*\(/' => 'putenv() affects all requests',
            '/ini_set\s*\(/' => 'ini_set() changes affect all requests',
            '/setlocale\s*\(/' => 'setlocale() changes affect all requests',
        ];

        foreach ($patterns as $pattern => $message) {
            // @phpstan-ignore-next-line Simple conditional check, safe usage
            if (preg_match($pattern, $code)) {
                $violations[] = [
                    'pattern' => $pattern,
                    'message' => $message,
                    'line' => 0, // Would need more sophisticated parsing for line numbers
                ];
            }
        }

        return $violations;
    }

    /**
     * Sanitize superglobal array
     */
    public static function sanitizeSuperglobal(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Skip dangerous keys
            if (in_array($key, ['GLOBALS', 'php://input'], true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeSuperglobal($value);
            } elseif (is_string($value)) {
                // Basic sanitization - remove null bytes and control characters
                $sanitized[$key] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get current memory usage for global state
     */
    public static function getGlobalStateMemoryUsage(): array
    {
        return [
            '_SERVER' => strlen(serialize($_SERVER)),
            '_GET' => strlen(serialize($_GET)),
            '_POST' => strlen(serialize($_POST)),
            '_COOKIE' => strlen(serialize($_COOKIE)),
            '_SESSION' => strlen(serialize($_SESSION ?? [])),
            '_FILES' => strlen(serialize($_FILES)),
            '_ENV' => strlen(serialize($_ENV)),
        ];
    }
}
