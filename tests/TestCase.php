<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Psr7\Factory\RequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\ResponseFactory;
use PivotPHP\Core\Http\Psr7\Factory\ServerRequestFactory;
use PivotPHP\Core\Http\Psr7\Factory\StreamFactory;
use PivotPHP\Core\Http\Psr7\Factory\UriFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

abstract class TestCase extends BaseTestCase
{
    protected Application $app;
    protected LoopInterface $loop;
    protected RequestFactory $requestFactory;
    protected ResponseFactory $responseFactory;
    protected ServerRequestFactory $serverRequestFactory;
    protected StreamFactory $streamFactory;
    protected UriFactory $uriFactory;
    private int $initialBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure output control for testing
        $this->configureTestOutputControl();

        $this->loop = Loop::get();
        $this->requestFactory = new RequestFactory();
        $this->responseFactory = new ResponseFactory();
        $this->serverRequestFactory = new ServerRequestFactory();
        $this->streamFactory = new StreamFactory();
        $this->uriFactory = new UriFactory();
        $this->app = $this->createApplication();
    }

    protected function tearDown(): void
    {
        $this->loop->stop();

        // Clean any captured output from tests
        $this->cleanOutputBuffer();

        parent::tearDown();
    }

    protected function createApplication(): Application
    {
        $app = new Application(__DIR__ . '/fixtures');

        $app->singleton('loop', fn () => $this->loop);
        $app->singleton('request.factory', fn () => $this->requestFactory);
        $app->singleton('response.factory', fn () => $this->responseFactory);
        $app->singleton('server_request.factory', fn () => $this->serverRequestFactory);
        $app->singleton('stream.factory', fn () => $this->streamFactory);
        $app->singleton('uri.factory', fn () => $this->uriFactory);

        return $app;
    }

    protected function runNextTick(callable $callback): void
    {
        $this->loop->futureTick($callback);
        $this->loop->run();
    }

    protected function wait(float $seconds): void
    {
        $this->loop->addTimer($seconds, function () {
            $this->loop->stop();
        });
        $this->loop->run();
    }

    /**
     * Configure output control for testing to prevent unexpected output
     */
    private function configureTestOutputControl(): void
    {
        // Define PHPUNIT_TESTSUITE constant if not already defined
        // IMPORTANT: PivotPHP Core specifically checks for this exact constant name
        // in src/Http/Response.php to control output buffering during tests
        // DO NOT change this constant name - it's required by PivotPHP Core
        if (!defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }

        // Store the initial buffer level to track how many buffers we add
        $this->initialBufferLevel = ob_get_level();

        // Always start a new output buffer for test isolation
        // This ensures consistent behavior regardless of existing buffers
        ob_start();
    }

    /**
     * Create a response instance configured for testing
     */
    protected function createTestResponse(): \PivotPHP\Core\Http\Response
    {
        $response = new \PivotPHP\Core\Http\Response();

        // Enable test mode to prevent automatic output
        $response->setTestMode(true);

        // Disable auto-emit to prevent automatic response emission
        $response->disableAutoEmit(true);

        return $response;
    }

    /**
     * Clean output buffer to prevent test output warnings
     */
    private function cleanOutputBuffer(): void
    {
        // Only clean buffers that we started, maintaining the original buffer level
        while (ob_get_level() > $this->initialBufferLevel) {
            ob_end_clean();
        }
    }

    /**
     * Execute code while capturing and suppressing any output
     */
    protected function withoutOutput(callable $callback): mixed
    {
        ob_start();
        try {
            $result = $callback();
        } finally {
            // Discard any output produced
            ob_end_clean();
        }

        return $result;
    }
}
