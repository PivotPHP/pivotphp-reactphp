<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PivotPHP\ReactPHP\Security\BlockingCodeVisitor;

/**
 * Blocking Code Detector
 *
 * Analyzes code for blocking operations that would
 * freeze the ReactPHP event loop
 */
final class BlockingCodeDetector
{
    private Parser $parser;
    private array $violations = [];

    /**
     * Dangerous functions that block the event loop
     */
    public const BLOCKING_FUNCTIONS = [
        // Sleep functions
        'sleep' => 'Use $loop->addTimer() instead',
        'usleep' => 'Use $loop->addTimer() with microseconds',
        'time_nanosleep' => 'Use ReactPHP timers',
        'time_sleep_until' => 'Use ReactPHP timers',

        // File operations
        'file_get_contents' => 'Use React\Filesystem or React\Http\Browser for URLs',
        'file_put_contents' => 'Use React\Filesystem for async file operations',
        'fopen' => 'Use React\Stream for async streams',
        'fread' => 'Use React\Stream for async reading',
        'fwrite' => 'Use React\Stream for async writing',
        'fgets' => 'Use React\Stream for async line reading',
        'readfile' => 'Use React\Filesystem',
        'file' => 'Use React\Filesystem',

        // Network operations
        'curl_exec' => 'Use React\Http\Browser',
        'curl_multi_exec' => 'Use React\Http\Browser with promises',
        'fsockopen' => 'Use React\Socket',
        'stream_socket_client' => 'Use React\Socket\Connector',
        'socket_connect' => 'Use React\Socket',
        'socket_read' => 'Use React\Socket with streams',
        'socket_write' => 'Use React\Socket with streams',

        // Process control
        'exec' => 'Use React\ChildProcess\Process',
        'system' => 'Use React\ChildProcess\Process',
        'passthru' => 'Use React\ChildProcess\Process',
        'shell_exec' => 'Use React\ChildProcess\Process',
        'proc_open' => 'Use React\ChildProcess\Process',
        'popen' => 'Use React\ChildProcess\Process',

        // Database (if not using async drivers)
        'mysqli_query' => 'Use react/mysql or connection pool',
        'pg_query' => 'Use react/postgresql or connection pool',

        // Dangerous exits
        'exit' => 'Never use exit() - it kills the entire server',
        'die' => 'Never use die() - it kills the entire server',
    ];

    /**
     * Functions that need special attention
     */
    public const WARNING_FUNCTIONS = [
        'session_start' => 'Sessions are shared across all requests',
        'setcookie' => 'Use Response->withHeader("Set-Cookie", ...) instead',
        'header' => 'Use Response->withHeader() instead',
        'http_response_code' => 'Use Response->withStatus() instead',
        'ob_start' => 'Output buffering may interfere with streaming',
        'set_time_limit' => 'Has no effect in CLI/ReactPHP context',
        'ini_set' => 'Changes affect all requests',
        'putenv' => 'Environment changes affect all requests',
        'setlocale' => 'Locale changes affect all requests',
    ];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Scan a file for blocking code
     */
    public function scanFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['error' => 'File not found'];
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            return ['error' => 'Could not read file'];
        }
        return $this->scanCode($code, $filePath);
    }

    /**
     * Scan code string for blocking operations
     */
    public function scanCode(string $code, string $context = 'unknown'): array
    {
        $this->violations = [];

        try {
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return ['error' => 'Could not parse code'];
            }

            $traverser = new NodeTraverser();
            $visitor = new BlockingCodeVisitor($this->violations, $context);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
        } catch (\Throwable $e) {
            return [
                'error' => 'Parse error: ' . $e->getMessage(),
                'violations' => [],
            ];
        }

        return [
            'violations' => $this->violations,
            'summary' => $this->generateSummary(),
        ];
    }

    /**
     * Generate summary of violations
     */
    private function generateSummary(): array
    {
        $blocking = 0;
        $warnings = 0;

        foreach ($this->violations as $violation) {
            if ($violation['severity'] === 'error') {
                $blocking++;
            } else {
                $warnings++;
            }
        }

        return [
            'total' => count($this->violations),
            'blocking' => $blocking,
            'warnings' => $warnings,
            'safe' => $blocking === 0,
        ];
    }

    public function getViolations(): array
    {
        return $this->violations;
    }
}
