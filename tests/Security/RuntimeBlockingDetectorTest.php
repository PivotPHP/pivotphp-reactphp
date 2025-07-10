<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Security;

use PivotPHP\ReactPHP\Security\RuntimeBlockingDetector;
use PivotPHP\ReactPHP\Tests\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

class RuntimeBlockingDetectorTest extends TestCase
{
    private RuntimeBlockingDetector $detector;
    private array $detectedBlocks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RuntimeBlockingDetector(0.05, 0.01); // 50ms threshold, 10ms sampling
        $this->detectedBlocks = [];
    }

    protected function tearDown(): void
    {
        $this->detector->disable();
        parent::tearDown();
    }

    public function testConstructorSetsDefaults(): void
    {
        $detector = new RuntimeBlockingDetector();
        $config = $detector->getConfig();

        $this->assertEquals(0.1, $config['threshold']);
        $this->assertEquals(0.01, $config['sampling_interval']);
        $this->assertFalse($config['enabled']);
        $this->assertEquals(0, $config['consecutive_blocking_count']);
    }

    public function testConstructorAcceptsCustomValues(): void
    {
        $detector = new RuntimeBlockingDetector(0.2, 0.05);
        $config = $detector->getConfig();

        $this->assertEquals(0.2, $config['threshold']);
        $this->assertEquals(0.05, $config['sampling_interval']);
    }

    public function testEnableStartsDetection(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $config = $this->detector->getConfig();

        $this->assertTrue($config['enabled']);
    }

    public function testDisableStopsDetection(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->disable();
        $config = $this->detector->getConfig();

        $this->assertFalse($config['enabled']);
        $this->assertEquals(0, $config['consecutive_blocking_count']);
    }

    public function testRecordActivityUpdatesTimestamp(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->setMaxConsecutiveBlocking(1);

        // Record activity to reset timer
        $this->detector->recordActivity();

        // Wait less than threshold
        $this->runLoop(0.03); // Run for 30ms (less than 50ms threshold)

        // Should not detect blocking
        $this->assertEmpty($this->detectedBlocks);
    }

    public function testDetectsBlockingAfterThreshold(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->setMaxConsecutiveBlocking(1); // Report immediately

        // Simulate blocking by not calling recordActivity
        $this->runLoop(0.1); // Run for 100ms (threshold is 50ms)

        // Should detect blocking
        $this->assertGreaterThan(0, count($this->detectedBlocks));
        $this->assertArrayHasKey('duration', $this->detectedBlocks[0]);
        $this->assertArrayHasKey('file', $this->detectedBlocks[0]);
        $this->assertArrayHasKey('line', $this->detectedBlocks[0]);
        $this->assertArrayHasKey('function', $this->detectedBlocks[0]);
        $this->assertArrayHasKey('sampling_interval', $this->detectedBlocks[0]);
        $this->assertArrayHasKey('consecutive_blocks', $this->detectedBlocks[0]);
    }

    public function testConsecutiveBlockingThreshold(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->setMaxConsecutiveBlocking(10); // Need many consecutive blocks before reporting

        // Simulate brief blocking (should not trigger due to high threshold)
        $this->runLoop(0.08); // Run for 80ms (longer than 50ms threshold)
        $this->assertEmpty($this->detectedBlocks); // Should be empty due to high threshold
    }

    public function testWrapFunctionRecordsActivity(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->setMaxConsecutiveBlocking(1);

        // Create wrapped function
        $wrappedFunction = $this->detector->wrapFunction(function (int $x) {
            return $x * 2;
        });

        // Call wrapped function to record activity
        $result = $wrappedFunction(5);
        $this->assertEquals(10, $result);

        // Run for short duration after activity was recorded
        $this->runLoop(0.03); // Run for 30ms (less than threshold)
        $this->assertEmpty($this->detectedBlocks);
    }

    public function testSetMaxConsecutiveBlocking(): void
    {
        // Test setting a valid value
        $this->detector->setMaxConsecutiveBlocking(10);
        $this->assertTrue(true); // Method should complete without error

        // Test minimum value enforcement
        $this->detector->setMaxConsecutiveBlocking(0);
        $this->assertTrue(true); // Should still work (minimum is 1)

        $this->detector->setMaxConsecutiveBlocking(-5);
        $this->assertTrue(true); // Should still work (minimum is 1)
    }

    public function testSampleWithDisabledDetector(): void
    {
        // Should not throw error when sampling with disabled detector
        $this->detector->sample();
        $this->assertTrue(true); // Just verify no exception
    }

    public function testRecordActivityWithDisabledDetector(): void
    {
        // Should not throw error when recording activity with disabled detector
        $this->detector->recordActivity();
        $this->assertTrue(true); // Just verify no exception
    }

    public function testBlockingDetectionWithActivityBetween(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->setMaxConsecutiveBlocking(2);

        // Simulate some blocking
        $this->runLoop(0.03); // 30ms

        // Record activity to reset counter
        $this->detector->recordActivity();

        // More blocking
        $this->runLoop(0.08); // 80ms

        // Should detect blocking but count should be reset
        $this->assertGreaterThan(0, count($this->detectedBlocks));
    }

    public function testCallbackDataStructure(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        $this->detector->enable($callback, $this->loop);
        $this->detector->setMaxConsecutiveBlocking(1);

        // Force blocking detection
        $this->runLoop(0.1);

        $this->assertGreaterThan(0, count($this->detectedBlocks));
        $data = $this->detectedBlocks[0];

        // Verify all required fields are present
        $this->assertIsFloat($data['duration']);
        $this->assertIsString($data['file']);
        $this->assertIsInt($data['line']);
        $this->assertIsString($data['function']);
        $this->assertIsFloat($data['sampling_interval']);
        $this->assertIsInt($data['consecutive_blocks']);

        // Verify duration is reasonable
        $this->assertGreaterThan(0.05, $data['duration']); // Should be > threshold
        $this->assertEquals(0.01, $data['sampling_interval']); // Should match setting
    }

    public function testMultipleEnableDisableCycles(): void
    {
        $callback = function (array $data) {
            $this->detectedBlocks[] = $data;
        };

        // Enable, disable, enable again
        $this->detector->enable($callback, $this->loop);
        $this->assertTrue($this->detector->getConfig()['enabled']);

        $this->detector->disable();
        $this->assertFalse($this->detector->getConfig()['enabled']);

        $this->detector->enable($callback, $this->loop);
        $this->assertTrue($this->detector->getConfig()['enabled']);

        $this->detector->disable();
        $this->assertFalse($this->detector->getConfig()['enabled']);
    }

    /**
     * Run the event loop for a specified duration
     */
    private function runLoop(float $duration): void
    {
        $endTime = microtime(true) + $duration;

        // Add a timer to stop the loop after the specified duration
        $timer = $this->loop->addTimer($duration, function () {
            $this->loop->stop();
        });

        // Run the loop
        $this->loop->run();

        // Cancel the timer if still active
        if ($timer !== null) {
            $this->loop->cancelTimer($timer);
        }
    }
}
