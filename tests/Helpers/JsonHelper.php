<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Helpers;

/**
 * Helper class for JSON operations with type safety
 */
final class JsonHelper
{
    /**
     * Safely decode JSON with type checking
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON must decode to an array');
        }

        return $decoded;
    }

    /**
     * Safely encode JSON with error handling
     */
    public static function encode(mixed $data): string
    {
        $encoded = json_encode($data);

        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /**
     * Extract a specific key from JSON response with type safety
     */
    public static function extractKey(string $json, string|int $key): mixed
    {
        $data = self::decode($json);

        if (!array_key_exists($key, $data)) {
            throw new \RuntimeException("Key '$key' not found in JSON");
        }

        return $data[$key];
    }

    /**
     * Check if JSON contains expected structure
     */
    public static function hasKeys(string $json, array $requiredKeys): bool
    {
        try {
            $data = self::decode($json);

            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $data)) {
                    return false;
                }
            }

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Validate JSON response structure for error responses
     */
    public static function validateErrorResponse(string $json): array
    {
        $data = self::decode($json);

        if (!isset($data['error'])) {
            throw new \RuntimeException('Error response must contain "error" key');
        }

        if (!is_array($data['error'])) {
            throw new \RuntimeException('Error data must be an array');
        }

        $error = $data['error'];

        if (!isset($error['message'])) {
            throw new \RuntimeException('Error response must contain "message" key');
        }

        return $data;
    }
}
