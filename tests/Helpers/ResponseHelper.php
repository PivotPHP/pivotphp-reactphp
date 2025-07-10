<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Helpers;

use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use PivotPHP\ReactPHP\Tests\Mocks\MockBrowser;

/**
 * Helper class for handling HTTP responses in tests
 */
final class ResponseHelper
{
    /**
     * Extract response body as array from Browser response
     */
    public static function getJsonBody(PromiseInterface $promise): array
    {
        $response = null;
        $promise->then(function ($result) use (&$response) {
            $response = $result;
        });

        if (!$response instanceof Response) {
            throw new \RuntimeException('Expected Response object');
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Response body is not valid JSON array');
        }

        return $decoded;
    }

    /**
     * Safely access array offset with type checking
     */
    public static function getArrayValue(mixed $data, string|int $key, mixed $default = null): mixed
    {
        if (!is_array($data)) {
            return $default;
        }

        return $data[$key] ?? $default;
    }

    /**
     * Assert response structure and extract data
     */
    public static function assertJsonResponse(mixed $response, int $expectedStatusCode = 200): array
    {
        if (!$response instanceof Response) {
            throw new \RuntimeException('Expected Response object');
        }

        if ($response->getStatusCode() !== $expectedStatusCode) {
            throw new \RuntimeException(
                sprintf('Expected status %d, got %d', $expectedStatusCode, $response->getStatusCode())
            );
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Response body is not valid JSON array');
        }

        return $decoded;
    }

    /**
     * Create a mock Browser with predictable responses
     */
    public static function createMockBrowser(array $responses = []): MockBrowser
    {
        $mockBrowser = new MockBrowser();

        foreach ($responses as $url => $response) {
            $mockBrowser->setResponse($url, $response);
        }

        return $mockBrowser;
    }
}
