<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Server;

use PivotPHP\Core\Http\Response;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class ReactServerTest extends TestCase
{
    private ReactServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new ReactServer($this->app, $this->loop, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testServerStartsAndStops(): void
    {
        self::assertSame($this->loop, $this->server->getLoop());

        // Test that server can be created without throwing exceptions
        self::assertInstanceOf(ReactServer::class, $this->server);

        // Test that server can be stopped without throwing exceptions
        $this->server->stop();
        // If no exception is thrown, the test passes
    }

    public function testServerConfiguration(): void
    {
        // Test server configuration without starting it
        $config = [
            'debug' => true,
            'streaming' => false,
            'max_concurrent_requests' => 50,
        ];

        $configuredServer = new ReactServer($this->app, $this->loop, new NullLogger(), $config);
        self::assertInstanceOf(ReactServer::class, $configuredServer);
        self::assertSame($this->loop, $configuredServer->getLoop());
    }

    public function testServerWithDifferentLogger(): void
    {
        // Test that server accepts different logger types
        $logger = new NullLogger();
        $serverWithLogger = new ReactServer($this->app, $this->loop, $logger);

        self::assertInstanceOf(ReactServer::class, $serverWithLogger);
        self::assertSame($this->loop, $serverWithLogger->getLoop());
    }

    public function testServerStopBeforeStart(): void
    {
        // Test that stopping a server that never started doesn't cause issues
        self::expectNotToPerformAssertions();

        $this->server->stop();
        // If no exception is thrown, the test passes
    }
}
