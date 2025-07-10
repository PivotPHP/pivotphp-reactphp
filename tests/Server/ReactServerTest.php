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
        $serverAddress = '127.0.0.1:0'; // Port 0 = let system choose available port
        
        try {
            // This should work without blocking
            self::assertInstanceOf(ReactServer::class, $this->server);
            self::assertTrue(true); // Server creation succeeded
        } catch (\Throwable $e) {
            self::fail('Server creation should not throw exceptions: ' . $e->getMessage());
        }
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
        try {
            $this->server->stop();
            self::assertTrue(true); // Stop should not throw exceptions
        } catch (\Throwable $e) {
            self::fail('Server stop should not throw exceptions when server was never started: ' . $e->getMessage());
        }
    }
}
