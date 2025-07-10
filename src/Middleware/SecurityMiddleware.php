<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Middleware;

use PivotPHP\ReactPHP\Security\RequestIsolationInterface;
use PivotPHP\ReactPHP\Middleware\SecurityException;
use PivotPHP\ReactPHP\Helpers\RequestHelper;
use PivotPHP\ReactPHP\Helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Security Middleware
 *
 * Enforces security policies for ReactPHP requests
 */
final class SecurityMiddleware implements MiddlewareInterface
{
    private RequestIsolationInterface $isolation;
    private LoggerInterface $logger;
    private array $config;

    /**
     * Security configuration defaults
     */
    private const DEFAULT_CONFIG = [
        'enable_isolation' => true,
        'enable_sandbox' => true,
        'max_request_size' => 10 * 1024 * 1024, // 10MB
        'max_uri_length' => 2048,
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'forbidden_headers' => ['X-Powered-By'],
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 100,
            'window_seconds' => 60,
        ],
        'timeout' => 30.0, // 30 seconds max per request
    ];

    private array $rateLimitStore = [];

    public function __construct(
        RequestIsolationInterface $isolation,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->isolation = $isolation;
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startTime = microtime(true);
        $contextId = null;

        try {
            // Pre-request security checks
            $this->performSecurityChecks($request);

            // Create isolated context
            if ($this->config['enable_isolation']) {
                $contextId = $this->isolation->createContext($request);
                $request = $request->withAttribute('request_context_id', $contextId);
            }

            // Apply rate limiting
            if ($this->config['rate_limit']['enabled']) {
                $this->enforceRateLimit($request);
            }

            // Process request with timeout protection
            $response = $this->processWithTimeout($request, $handler, $startTime);

            // Post-request security headers
            $response = ResponseHelper::addSecurityHeaders($response, $this->isProduction());

            return $response;
        } catch (SecurityException $e) {
            $this->logger->warning('Security violation', [
                'message' => $e->getMessage(),
                'request_uri' => (string) $request->getUri(),
                'client_ip' => RequestHelper::getClientIp($request),
            ]);

            return ResponseHelper::createErrorResponse($e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error in security middleware', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::createErrorResponse(500, 'Internal Server Error');
        } finally {
            // Always cleanup context
            if ($contextId !== null) {
                $this->isolation->destroyContext($contextId);
            }

            // Clean old rate limit entries
            $this->cleanRateLimitStore();
        }
    }

    /**
     * Perform pre-request security checks
     */
    private function performSecurityChecks(ServerRequestInterface $request): void
    {
        // Check request method
        $method = $request->getMethod();
        if (!in_array($method, $this->config['allowed_methods'], true)) {
            throw new SecurityException("Method not allowed: $method", 405);
        }

        // Check URI length
        $uri = (string) $request->getUri();
        if (strlen($uri) > $this->config['max_uri_length']) {
            throw new SecurityException('URI too long', 414);
        }

        // Check request size
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && (int) $contentLength > $this->config['max_request_size']) {
            throw new SecurityException('Request entity too large', 413);
        }

        // Check for forbidden headers
        foreach ($this->config['forbidden_headers'] as $header) {
            if ($request->hasHeader($header)) {
                throw new SecurityException("Forbidden header: $header", 400);
            }
        }

        // Validate host header
        $this->validateHostHeader($request);
    }

    /**
     * Validate host header to prevent host header injection
     */
    private function validateHostHeader(ServerRequestInterface $request): void
    {
        $host = $request->getHeaderLine('Host');
        if ($host === '') {
            throw new SecurityException('Missing Host header', 400);
        }

        // Basic validation - adjust based on your needs
        if (preg_match('/^[a-zA-Z0-9.-]+(:\d+)?$/', $host) !== 1) {
            throw new SecurityException('Invalid Host header', 400);
        }
    }

    /**
     * Enforce rate limiting
     */
    private function enforceRateLimit(ServerRequestInterface $request): void
    {
        $clientId = RequestHelper::getClientIdentifier($request);
        $now = time();
        $window = $this->config['rate_limit']['window_seconds'];
        $maxRequests = $this->config['rate_limit']['max_requests'];

        if (!isset($this->rateLimitStore[$clientId])) {
            $this->rateLimitStore[$clientId] = [];
        }

        // Remove old entries
        $this->rateLimitStore[$clientId] = array_filter(
            $this->rateLimitStore[$clientId],
            fn($timestamp) => $timestamp > ($now - $window)
        );

        // Check limit
        if (count($this->rateLimitStore[$clientId]) >= $maxRequests) {
            throw new SecurityException('Rate limit exceeded', 429);
        }

        // Add current request
        $this->rateLimitStore[$clientId][] = $now;
    }

    /**
     * Process request with timeout protection
     */
    private function processWithTimeout(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        float $startTime
    ): ResponseInterface {
        // In a real implementation, we'd use ReactPHP's timer
        // For now, we'll just check elapsed time periodically

        $response = $handler->handle($request);

        $elapsed = microtime(true) - $startTime;
        if ($elapsed > $this->config['timeout']) {
            $this->logger->warning('Request timeout', [
                'elapsed' => $elapsed,
                'timeout' => $this->config['timeout'],
                'uri' => (string) $request->getUri(),
            ]);
        }

        return $response;
    }



    /**
     * Clean old rate limit entries
     */
    private function cleanRateLimitStore(): void
    {
        $now = time();
        $window = $this->config['rate_limit']['window_seconds'];

        foreach ($this->rateLimitStore as $clientId => $timestamps) {
            $this->rateLimitStore[$clientId] = array_filter(
                $timestamps,
                fn($timestamp) => $timestamp > ($now - $window)
            );

            if ($this->rateLimitStore[$clientId] === []) {
                unset($this->rateLimitStore[$clientId]);
            }
        }
    }


    /**
     * Check if running in production
     */
    private function isProduction(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'production') === 'production';
    }
}
