<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use PivotPHP\ReactPHP\Security\RequestContext;
use PivotPHP\ReactPHP\Security\SuperGlobalHandler;

/**
 * Global State Sandbox
 *
 * Prevents global state pollution between requests
 * by intercepting and isolating global variable access
 */
final class GlobalStateSandbox
{
    private array $allowedGlobals = [
        'argv', 'argc', // CLI arguments
    ];

    private array $readOnlyGlobals = [
        'PHP_VERSION', 'PHP_OS', 'PHP_SAPI',
        'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN',
    ];

    private array $globalSnapshots = [];
    private array $superGlobalHandlers = [];
    private bool $strictMode = false;

    /**
     * Enable sandbox in strict mode
     */
    public function enableStrictMode(): void
    {
        $this->strictMode = true;
        $this->installHandlers();
    }

    /**
     * Install superglobal handlers
     */
    private function installHandlers(): void
    {
        // Note: This is a conceptual implementation
        // In practice, we'd need to use a PHP extension or
        // carefully manage access through Request objects

        $this->superGlobalHandlers = [
            '_GET' => new SuperGlobalHandler('_GET'),
            '_POST' => new SuperGlobalHandler('_POST'),
            '_SESSION' => new SuperGlobalHandler('_SESSION'),
            '_COOKIE' => new SuperGlobalHandler('_COOKIE'),
            '_SERVER' => new SuperGlobalHandler('_SERVER'),
            '_ENV' => new SuperGlobalHandler('_ENV'),
            '_FILES' => new SuperGlobalHandler('_FILES'),
        ];
    }

    /**
     * Create isolated context for request
     */
    public function createRequestContext(string $requestId): RequestContext
    {
        $context = new RequestContext($requestId);

        // Initialize with clean superglobals
        $context->set('_GET', []);
        $context->set('_POST', []);
        $context->set('_SESSION', []);
        $context->set('_COOKIE', []);
        $context->set('_FILES', []);
        $context->set('_SERVER', $this->getSafeServerVars());
        $context->set('_ENV', $this->getSafeEnvVars());

        return $context;
    }

    /**
     * Get safe SERVER variables
     */
    private function getSafeServerVars(): array
    {
        $safe = [];
        $allowed = [
            'SERVER_SOFTWARE', 'SERVER_PROTOCOL',
            'GATEWAY_INTERFACE', 'PHP_SELF',
            'SCRIPT_NAME', 'SCRIPT_FILENAME',
            'DOCUMENT_ROOT', 'SERVER_ADMIN',
        ];

        foreach ($allowed as $key) {
            if (isset($_SERVER[$key])) {
                $safe[$key] = $_SERVER[$key];
            }
        }

        return $safe;
    }

    /**
     * Get safe ENV variables
     */
    private function getSafeEnvVars(): array
    {
        $safe = [];
        $allowed = [
            'PATH', 'HOME', 'USER',
            'LANG', 'LC_ALL', 'TZ',
        ];

        foreach ($allowed as $key) {
            if (isset($_ENV[$key])) {
                $safe[$key] = $_ENV[$key];
            }
        }

        return $safe;
    }

    /**
     * Check for global state violations
     */
    public function checkViolations(string $code): array
    {
        $violations = [];

        // Check for direct superglobal access
        $patterns = [
            '/\$GLOBALS\s*\[/' => 'Direct $GLOBALS access is forbidden',
            '/\$_SESSION\s*\[/' => 'Direct $_SESSION access is forbidden',
            '/\$_GET\s*\[/' => 'Use $request->getQueryParams() instead',
            '/\$_POST\s*\[/' => 'Use $request->getParsedBody() instead',
            '/\$_COOKIE\s*\[/' => 'Use $request->getCookieParams() instead',
            '/\$_SERVER\s*\[/' => 'Use $request->getServerParams() instead',
            '/\$_FILES\s*\[/' => 'Use $request->getUploadedFiles() instead',
            '/global\s+\$/' => 'Global keyword is forbidden',
            '/putenv\s*\(/' => 'putenv() affects all requests',
            '/setcookie\s*\(/' => 'Use Response->withHeader("Set-Cookie") instead',
            '/session_start\s*\(/' => 'Native sessions are shared across all requests',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $violations[] = [
                    'pattern' => $pattern,
                    'message' => $message,
                    'offset' => $matches[0][1],
                ];
            }
        }

        return $violations;
    }

    public function getAllowedGlobals(): array
    {
        return $this->allowedGlobals;
    }

    public function getReadOnlyGlobals(): array
    {
        return $this->readOnlyGlobals;
    }

    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    public function getGlobalSnapshots(): array
    {
        return $this->globalSnapshots;
    }

    public function getSuperGlobalHandlers(): array
    {
        return $this->superGlobalHandlers;
    }
}
