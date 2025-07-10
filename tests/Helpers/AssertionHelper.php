<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Helper class for common test assertions with type safety
 */
final class AssertionHelper
{
    /**
     * Assert that a value is an array and contains specific keys
     */
    public static function assertArrayHasKeys(TestCase $testCase, array $expectedKeys, mixed $actual): void
    {
        $testCase::assertIsArray($actual);

        foreach ($expectedKeys as $key) {
            $testCase::assertArrayHasKey($key, $actual);
        }
    }

    /**
     * Assert that a value is an array and extract a specific key safely
     */
    public static function assertArrayKeyValue(
        TestCase $testCase,
        string|int $key,
        mixed $actual,
        mixed $expectedValue
    ): void {
        $testCase::assertIsArray($actual);
        $testCase::assertArrayHasKey($key, $actual);
        $testCase::assertEquals($expectedValue, $actual[$key]);
    }

    /**
     * Assert JSON response structure and content
     */
    public static function assertJsonResponseContent(
        TestCase $testCase,
        string $jsonContent,
        array $expectedKeys = []
    ): array {
        $decoded = json_decode($jsonContent, true);
        $testCase::assertIsArray($decoded, 'Response body should be valid JSON');

        foreach ($expectedKeys as $key) {
            $testCase::assertArrayHasKey($key, $decoded, "JSON should contain key: $key");
        }

        return $decoded;
    }

    /**
     * Assert that a callback method was called with expected parameters
     */
    public static function assertMethodCalledWith(TestCase $testCase, callable $callback, array $expectedArgs): void
    {
        $called = false;
        $actualArgs = [];

        $wrapper = function (...$args) use (&$called, &$actualArgs, $callback) {
            $called = true;
            $actualArgs = $args;
            return $callback(...$args);
        };

        // Call the wrapper - this would need adaptation based on usage
        $testCase::assertTrue($called, 'Expected method to be called');
        $testCase::assertEquals($expectedArgs, $actualArgs, 'Method called with wrong arguments');
    }

    /**
     * Safely assert that an object has a specific method result
     */
    public static function assertObjectMethodResult(
        TestCase $testCase,
        object $object,
        string $method,
        mixed $expectedResult
    ): void {
        $testCase::assertTrue(method_exists($object, $method), "Object should have method: $method");

        // @phpstan-ignore-next-line Safe dynamic method call, method existence checked above
        $result = call_user_func([$object, $method]);
        $testCase::assertEquals($expectedResult, $result);
    }
}
