<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Helpers;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PivotPHP\ReactPHP\Security\RequestIsolation;

/**
 * Helper class for creating standardized mocks in tests
 */
final class MockHelper
{
    /**
     * Get LoggerInterface class name for mock creation
     */
    public static function getLoggerClass(): string
    {
        return LoggerInterface::class;
    }

    /**
     * Get RequestIsolation class name for mock creation
     */
    public static function getRequestIsolationClass(): string
    {
        return RequestIsolation::class;
    }

    /**
     * Setup logger mock with common expectations
     */
    public static function setupLoggerExpectations(
        MockObject $logger,
        TestCase $testCase,
        string $method = 'info',
        int $times = 1
    ): void {
        $logger->expects($testCase::exactly($times))
            ->method($method)
            ->with($testCase::anything());
    }

    /**
     * Setup RequestIsolation mock with context expectations
     */
    public static function setupIsolationExpectations(
        MockObject $isolation,
        TestCase $testCase,
        string $contextId = 'test-context'
    ): void {
        $isolation->expects($testCase::once())
            ->method('createContext')
            ->willReturn($contextId);
        $isolation->expects($testCase::once())
            ->method('destroyContext')
            ->with($contextId);
    }
}
