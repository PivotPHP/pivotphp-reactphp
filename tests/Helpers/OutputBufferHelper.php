<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Helpers;

/**
 * Helper for managing output buffering in tests
 */
final class OutputBufferHelper
{
    // @phpstan-ignore-next-line Property used for output buffer state management
    private static ?string $capturedOutput = null;

    /**
     * Start capturing output
     */
    public static function start(): void
    {
        ob_start();
    }

    /**
     * Stop capturing and return captured output
     */
    public static function stop(): string
    {
        $output = ob_get_contents();
        ob_end_clean();

        return $output !== false ? $output : '';
    }

    /**
     * Execute a callable while capturing output
     */
    public static function capture(callable $callback): array
    {
        self::start();
        $result = $callback();
        $output = self::stop();

        return [
            'result' => $result,
            'output' => $output
        ];
    }

    /**
     * Assert no output was produced
     */
    public static function assertNoOutput(string $output, string $message = 'Unexpected output produced'): void
    {
        if ($output !== '') {
            throw new \PHPUnit\Framework\AssertionFailedError(
                $message . ': "' . $output . '"'
            );
        }
    }
}
