<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Helpers;

/**
 * Helper for safe JSON operations with proper error handling
 */
final class JsonHelper
{
    /**
     * Safely encode data to JSON with fallback
     */
    public static function encode(mixed $data, string $fallback = '{}'): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            error_log('JSON encoding failed: ' . json_last_error_msg());
            return $fallback;
        }

        return $encoded;
    }

    /**
     * Safely decode JSON string to array
     */
    public static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decoding failed: ' . json_last_error_msg());
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Validate if string is valid JSON
     */
    public static function isValid(string $json): bool
    {
        if (trim($json) === '') {
            return false;
        }

        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Create standardized error response JSON
     */
    public static function createErrorResponse(
        string $message,
        int $code = 500,
        array $details = []
    ): string {
        $data = [
            'error' => true,
            'code' => $code,
            'message' => $message,
            'timestamp' => date('c'),
        ];

        // @phpstan-ignore-next-line Array check for additional data
        if (count($details) > 0) {
            $data['details'] = $details;
        }

        return self::encode($data, '{"error":true,"message":"Internal Error"}');
    }

    /**
     * Extract specific key from JSON string safely
     */
    public static function extractKey(string $json, string $key, mixed $default = null): mixed
    {
        $data = self::decode($json);

        if ($data === null) {
            return $default;
        }

        return $data[$key] ?? $default;
    }

    /**
     * Check if JSON contains all required keys
     */
    public static function hasRequiredKeys(string $json, array $requiredKeys): bool
    {
        $data = self::decode($json);

        if ($data === null) {
            return false;
        }

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Merge JSON strings safely
     */
    public static function merge(string $json1, string $json2): ?string
    {
        $data1 = self::decode($json1);
        $data2 = self::decode($json2);

        if ($data1 === null || $data2 === null) {
            return null;
        }

        $merged = array_merge($data1, $data2);
        return self::encode($merged);
    }

    /**
     * Pretty print JSON for debugging
     */
    public static function prettyPrint(mixed $data): string
    {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $encoded !== false ? $encoded : '{}';
    }

    /**
     * Get last JSON error message
     */
    public static function getLastError(): string
    {
        return json_last_error_msg();
    }
}
