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

    protected function setUp(): void
    {
        parent::setUp();

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
}
