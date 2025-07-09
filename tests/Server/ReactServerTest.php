<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Server;

use PivotPHP\Core\Http\Response;
use PivotPHP\ReactPHP\Server\ReactServer;
use PivotPHP\ReactPHP\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use React\Http\Browser;
use React\Promise\Promise;

final class ReactServerTest extends TestCase
{
    private ReactServer $server;
    private Browser $browser;
    private string $serverAddress = '127.0.0.1:18080';

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app->make('router');
        $router->get('/', fn () => Response::json(['message' => 'Hello, World!']));
        $router->get('/error', fn () => throw new \RuntimeException('Test error'));

        $this->server = new ReactServer($this->app, $this->loop, new NullLogger());
        $this->browser = new Browser(null, $this->loop);

        $this->loop->futureTick(function () {
            $this->server->listen($this->serverAddress);
        });
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testServerStartsAndStops(): void
    {
        $this->assertInstanceOf(ReactServer::class, $this->server);
        $this->assertSame($this->loop, $this->server->getLoop());
    }

    public function testHandleRequest(): void
    {
        $promise = $this->browser->get("http://{$this->serverAddress}/");

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
            $this->loop->stop();
        });

        $this->loop->run();

        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals(['message' => 'Hello, World!'], $body);
    }

    public function testHandleRequestWithError(): void
    {
        $promise = $this->browser->get("http://{$this->serverAddress}/error");

        $response = null;
        $promise->then(function ($res) use (&$response) {
            $response = $res;
            $this->loop->stop();
        });

        $this->loop->run();

        $this->assertNotNull($response);
        $this->assertEquals(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Internal Server Error', $body['error']);
    }

    public function testConcurrentRequests(): void
    {
        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $promises[] = $this->browser->get("http://{$this->serverAddress}/");
        }

        $responses = [];
        \React\Promise\all($promises)->then(function ($results) use (&$responses) {
            $responses = $results;
            $this->loop->stop();
        });

        $this->loop->run();

        $this->assertCount(5, $responses);
        foreach ($responses as $response) {
            $this->assertEquals(200, $response->getStatusCode());
        }
    }
}
