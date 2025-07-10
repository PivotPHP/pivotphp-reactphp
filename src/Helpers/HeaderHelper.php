<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Helpers;

/**
 * Helper for header processing and conversion between different formats
 */
final class HeaderHelper
{
    /**
     * Convert PSR-7 headers array to simple array format
     */
    public static function convertPsrToArray(array $headers): array
    {
        $converted = [];
        foreach ($headers as $name => $values) {
            $converted[$name] = self::normalizeHeaderValue($values);
        }
        return $converted;
    }

    /**
     * Convert simple array to PSR-7 compatible format
     */
    public static function convertArrayToPsr(array $headers): array
    {
        $converted = [];
        foreach ($headers as $name => $value) {
            $converted[$name] = is_array($value) ? $value : [$value];
        }
        return $converted;
    }

    /**
     * Normalize header value to string format
     */
    public static function normalizeHeaderValue(mixed $values): string
    {
        if (is_array($values)) {
            return implode(', ', array_map('strval', $values));
        }

        // @phpstan-ignore-next-line Safe string conversion for header values
        return (string) $values;
    }

    /**
     * Validate header name according to HTTP standards
     */
    public static function validateHeaderName(string $name): bool
    {
        // HTTP header names are case-insensitive and can contain letters, digits, and hyphens
        return preg_match('/^[a-zA-Z0-9\-_]+$/', $name) === 1;
    }

    /**
     * Convert HTTP header name to camelCase (PivotPHP format)
     */
    public static function toCamelCase(string $headerName): string
    {
        // Convert "Content-Type" to "contentType", "X-API-Key" to "xApiKey"
        $parts = explode('-', strtolower($headerName));
        $camelCase = array_shift($parts) ?? '';

        foreach ($parts as $part) {
            // @phpstan-ignore-next-line Safe string casting from array elements
            $camelCase .= ucfirst((string) $part);
        }

        return $camelCase;
    }

    /**
     * Convert camelCase back to HTTP header format
     */
    public static function fromCamelCase(string $camelCase): string
    {
        // Convert "contentType" to "Content-Type", "xApiKey" to "X-Api-Key"
        $result = preg_replace('/([a-z])([A-Z])/', '$1-$2', $camelCase);
        return $result !== null ? ucwords($result, '-') : $camelCase;
    }

    /**
     * Get security headers for responses
     */
    public static function getSecurityHeaders(bool $isProduction = false): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];

        if ($isProduction) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Parse Content-Type header into components
     */
    public static function parseContentType(string $contentType): array
    {
        $parts = explode(';', $contentType);
        $mediaType = trim($parts[0]);
        $params = [];

        for ($i = 1; $i < count($parts); $i++) {
            $param = trim($parts[$i]);
            if (str_contains($param, '=')) {
                [$key, $value] = explode('=', $param, 2);
                $params[trim($key)] = trim($value, ' "');
            }
        }

        return [
            'media_type' => $mediaType,
            'parameters' => $params,
        ];
    }
}
