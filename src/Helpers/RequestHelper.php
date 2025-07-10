<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Helpers;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Helper for request processing and client identification
 */
final class RequestHelper
{
    /**
     * Extract client IP address with proxy support
     */
    public static function getClientIp(
        ServerRequestInterface $request,
        bool $trustProxies = false
    ): string {
        if ($trustProxies) {
            // Check forwarded headers (only if proxies are trusted)
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $ips = explode(',', $forwarded);
                $clientIp = trim($ips[0]);
                if (self::isValidIp($clientIp)) {
                    return $clientIp;
                }
            }

            // Check other proxy headers
            $proxyHeaders = [
                'X-Real-IP',
                'X-Client-IP',
                'CF-Connecting-IP', // Cloudflare
            ];

            foreach ($proxyHeaders as $header) {
                $ip = $request->getHeaderLine($header);
                if ($ip !== '' && self::isValidIp($ip)) {
                    return $ip;
                }
            }
        }

        // Fallback to direct connection
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? 'unknown';

        return self::isValidIp($remoteAddr) ? $remoteAddr : 'unknown';
    }

    /**
     * Create unique client identifier for rate limiting
     */
    public static function getClientIdentifier(
        ServerRequestInterface $request,
        bool $includeUserAgent = true
    ): string {
        $ip = self::getClientIp($request);
        $identifier = $ip;

        if ($includeUserAgent) {
            $userAgent = $request->getHeaderLine('User-Agent');
            $identifier .= '|' . $userAgent;
        }

        return hash('sha256', $identifier);
    }

    /**
     * Check if request is secure (HTTPS)
     */
    public static function isSecureRequest(ServerRequestInterface $request): bool
    {
        $uri = $request->getUri();
        if ($uri->getScheme() === 'https') {
            return true;
        }

        // Check proxy headers
        $serverParams = $request->getServerParams();

        // Standard HTTPS indicators
        if (isset($serverParams['HTTPS']) && $serverParams['HTTPS'] !== 'off') {
            return true;
        }

        // Proxy headers
        $httpsHeaders = [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_SSL' => 'on',
            'HTTP_X_FORWARDED_SCHEME' => 'https',
        ];

        foreach ($httpsHeaders as $header => $value) {
            if (isset($serverParams[$header]) && $serverParams[$header] === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get User-Agent string
     */
    public static function getUserAgent(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('User-Agent') !== '' ? $request->getHeaderLine('User-Agent') : 'unknown';
    }

    /**
     * Get request content type
     */
    public static function getContentType(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('Content-Type') !== ''
            ? $request->getHeaderLine('Content-Type')
            : 'application/octet-stream';
    }

    /**
     * Check if request expects JSON response
     */
    public static function expectsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'application/json') ||
               str_contains($accept, 'application/*') ||
               str_contains($accept, '*/*');
    }

    /**
     * Check if request is AJAX
     */
    public static function isAjax(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Get request size in bytes
     */
    public static function getRequestSize(ServerRequestInterface $request): int
    {
        $contentLength = $request->getHeaderLine('Content-Length');

        if ($contentLength === '') {
            // Estimate from body if available
            $body = $request->getBody();
            return $body->getSize() ?? 0;
        }

        return (int) $contentLength;
    }

    /**
     * Extract basic auth credentials
     */
    public static function getBasicAuth(ServerRequestInterface $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!str_starts_with($authHeader, 'Basic ')) {
            return null;
        }

        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Get bearer token from Authorization header
     */
    public static function getBearerToken(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7);
    }

    /**
     * Validate IP address format
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Check if IP is private/local
     */
    public static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Get request fingerprint for security
     */
    public static function getFingerprint(ServerRequestInterface $request): string
    {
        $components = [
            self::getClientIp($request),
            $request->getHeaderLine('User-Agent'),
            $request->getHeaderLine('Accept-Language'),
            $request->getHeaderLine('Accept-Encoding'),
        ];

        return hash('sha256', implode('|', $components));
    }
}
