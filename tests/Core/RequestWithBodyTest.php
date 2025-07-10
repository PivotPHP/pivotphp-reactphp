<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Core;

use PivotPHP\Core\Http\Request;
use PivotPHP\Core\Http\Psr7\Factory\StreamFactory;
use PivotPHP\ReactPHP\Tests\TestCase;

class RequestWithBodyTest extends TestCase
{
    public function testWithBodyStoresCustomStreamCorrectly(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('{"test":"data"}');

        $newRequest = $request->withBody($stream);

        $bodyStream = $newRequest->getBody();
        $this->assertEquals('{"test":"data"}', (string) $bodyStream);
    }

    public function testWithBodySupportsMultipleReads(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('{"test":"data"}');

        $newRequest = $request->withBody($stream);

        // First read
        $firstRead = (string) $newRequest->getBody();
        $this->assertEquals('{"test":"data"}', $firstRead);

        // Second read
        $secondRead = (string) $newRequest->getBody();
        $this->assertEquals('{"test":"data"}', $secondRead);
    }

    public function testWithBodyUpdatesExpressJsBodyObject(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('{"test":"data","number":42}');

        $newRequest = $request->withBody($stream);

        // Test Express.js style access
        $this->assertEquals('data', $newRequest->input('test'));
        $this->assertEquals(42, $newRequest->input('number'));
    }

    public function testWithBodyIsImmutable(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('{"test":"data"}');

        $newRequest = $request->withBody($stream);

        // Original request should be unaffected
        $this->assertEquals('', (string) $request->getBody());

        // New request should have the body
        $this->assertEquals('{"test":"data"}', (string) $newRequest->getBody());
    }

    public function testWithBodyCanChainMultipleChanges(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();

        $stream1 = $streamFactory->createStream('{"first":"data"}');
        $request1 = $request->withBody($stream1);

        $stream2 = $streamFactory->createStream('{"second":"data"}');
        $request2 = $request1->withBody($stream2);

        // Each request should have its own body
        $this->assertEquals('{"first":"data"}', (string) $request1->getBody());
        $this->assertEquals('{"second":"data"}', (string) $request2->getBody());

        // Express.js style access
        $this->assertEquals('data', $request1->input('first'));
        $this->assertEquals('data', $request2->input('second'));
    }

    public function testWithBodyHandlesEmptyStream(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('');

        $newRequest = $request->withBody($stream);

        $this->assertEquals('', (string) $newRequest->getBody());
    }

    public function testWithBodyHandlesNonJsonContent(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('plain text content');

        $newRequest = $request->withBody($stream);

        $this->assertEquals('plain text content', (string) $newRequest->getBody());
        // Express.js body should be empty object for non-JSON content
        $this->assertNull($newRequest->input('nonexistent'));
    }

    public function testWithBodyHandlesInvalidJsonContent(): void
    {
        $request = new Request('POST', '/api', '/api');
        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStream('{"invalid": json}');

        $newRequest = $request->withBody($stream);

        $this->assertEquals('{"invalid": json}', (string) $newRequest->getBody());
        // Express.js body should be empty object for invalid JSON
        $this->assertNull($newRequest->input('invalid'));
    }
}
