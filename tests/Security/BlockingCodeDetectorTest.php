<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Security;

use PivotPHP\ReactPHP\Security\BlockingCodeDetector;
use PivotPHP\ReactPHP\Tests\TestCase;

final class BlockingCodeDetectorTest extends TestCase
{
    private BlockingCodeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new BlockingCodeDetector();
    }

    public function testDetectsSleepFunction(): void
    {
        $code = '<?php
            function doSomething() {
                sleep(5);
                return "done";
            }
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertArrayHasKey
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertArrayHasKey
        $this->assertArrayHasKey('violations', $result);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertNotEmpty
        $this->assertNotEmpty($result['violations']);

        $violation = $result['violations'][0];
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals('blocking_function', $violation['type']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals('error', $violation['severity']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals('sleep', $violation['function']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertStringContainsString
        $this->assertStringContainsString('will freeze the server', $violation['message']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertStringContainsString
        $this->assertStringContainsString('$loop->addTimer()', $violation['suggestion']);
    }

    public function testDetectsFileGetContents(): void
    {
        $code = '<?php
            $data = file_get_contents("https://api.example.com/data");
            $localFile = file_get_contents("/path/to/file.txt");
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertCount
        $this->assertCount(2, $result['violations']);

        foreach ($result['violations'] as $violation) {
            // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
            $this->assertEquals('file_get_contents', $violation['function']);
            // PHPUnit\Framework\Assert::assertStringContainsString
            // @phpstan-ignore-next-line Dynamic call to static method
            $this->assertStringContainsString('React\Filesystem', $violation['suggestion']);
        }
    }

    public function testDetectsCurlExec(): void
    {
        $code = '<?php
            $ch = curl_init("https://example.com");
            $result = curl_exec($ch);
            curl_close($ch);
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        $violations = array_filter($result['violations'], function ($v) {
            return $v['function'] === 'curl_exec';
        });

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertCount
        $this->assertCount(1, $violations);
        $violation = reset($violations);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertStringContainsString
        $this->assertStringContainsString('React\Http\Browser', $violation['suggestion']);
    }

    public function testDetectsExitAndDie(): void
    {
        $code = '<?php
            if ($error) {
                die("Fatal error");
            }
            
            if ($shouldExit) {
                exit(1);
            }
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertCount
        $this->assertCount(2, $result['violations']);

        $functions = array_column($result['violations'], 'function');
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('die', $functions);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('exit', $functions);

        foreach ($result['violations'] as $violation) {
            // PHPUnit\Framework\Assert::assertStringContainsString
            // @phpstan-ignore-next-line Dynamic call to static method
            $this->assertStringContainsString('kills the entire server', $violation['message']);
        }
    }

    public function testDetectsGlobalVariableAccess(): void
    {
        $code = '<?php
            $userData = $GLOBALS["user"];
            $_SESSION["user_id"] = 123;
            $server = $_SERVER["HTTP_HOST"];
            $_ENV["APP_KEY"] = "secret";
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        $globalViolations = array_filter($result['violations'], function ($v) {
            return $v['type'] === 'global_access';
        });

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertCount
        $this->assertCount(4, $globalViolations);

        $variables = array_column($globalViolations, 'variable');
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('$GLOBALS', $variables);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('$_SESSION', $variables);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('$_SERVER', $variables);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('$_ENV', $variables);
    }

    public function testDetectsStaticVariables(): void
    {
        $code = '<?php
            function counter() {
                static $count = 0;
                return ++$count;
            }
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        $staticViolations = array_filter($result['violations'], function ($v) {
            return $v['type'] === 'static_variable';
        });

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertCount
        $this->assertCount(1, $staticViolations);
        $violation = reset($staticViolations);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertStringContainsString
        $this->assertStringContainsString('persist across requests', $violation['message']);
    }

    public function testDetectsInfiniteLoops(): void
    {
        $code = '<?php
            while (true) {
                processData();
            }
            
            while (1) {
                checkStatus();
            }
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        $loopViolations = array_filter($result['violations'], function ($v) {
            return $v['type'] === 'infinite_loop';
        });

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertCount
        $this->assertCount(2, $loopViolations);

        foreach ($loopViolations as $violation) {
            // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
            $this->assertEquals('error', $violation['severity']);
            // PHPUnit\Framework\Assert::assertStringContainsString
            // @phpstan-ignore-next-line Dynamic call to static method
            $this->assertStringContainsString('block the server', $violation['message']);
        }
    }

    public function testDetectsWarningFunctions(): void
    {
        $code = '<?php
            session_start();
            setcookie("user", "john");
            header("Location: /home");
            ob_start();
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        $warnings = array_filter($result['violations'], function ($v) {
            return $v['severity'] === 'warning';
        });

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertGreaterThanOrEqual
        $this->assertGreaterThanOrEqual(4, count($warnings));

        $functions = array_column($warnings, 'function');
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('session_start', $functions);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('setcookie', $functions);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('header', $functions);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertContains
        $this->assertContains('ob_start', $functions);
    }

    public function testScanFileNotFound(): void
    {
        $result = $this->detector->scanFile('/path/that/does/not/exist.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertArrayHasKey
        $this->assertArrayHasKey('error', $result);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals('File not found', $result['error']);
    }

    public function testScanCodeWithParseError(): void
    {
        $code = '<?php
            function broken() {
                // Missing closing brace
        ';

        $result = $this->detector->scanCode($code, 'broken.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertArrayHasKey
        $this->assertArrayHasKey('error', $result);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertStringContainsString
        $this->assertStringContainsString('Parse error', $result['error']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEmpty
        $this->assertEmpty($result['violations']);
    }

    public function testSummaryGeneration(): void
    {
        $code = '<?php
            sleep(1); // Error
            file_get_contents("url"); // Error
            $_SESSION["test"] = 1; // Warning
            header("X-Test: value"); // Warning
        ';

        $result = $this->detector->scanCode($code, 'test.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertArrayHasKey
        $this->assertArrayHasKey('summary', $result);
        $summary = $result['summary'];

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals(4, $summary['total']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals(2, $summary['blocking']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals(2, $summary['warnings']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertFalse
        $this->assertFalse($summary['safe']);
    }

    public function testSafeCodePasses(): void
    {
        $code = '<?php
            use React\EventLoop\Loop;
            use React\Http\Browser;
            
            class SafeController
            {
                private Browser $browser;
                
                public function __construct(Browser $browser)
                {
                    $this->browser = $browser;
                }
                
                public function fetchData(): Promise
                {
                    return $this->browser->get("https://api.example.com")
                        ->then(function ($response) {
                            return json_decode((string) $response->getBody(), true);
                        });
                }
                
                public function delayedAction(LoopInterface $loop): void
                {
                    $loop->addTimer(5.0, function () {
                        echo "Delayed action executed\n";
                    });
                }
            }
        ';

        $result = $this->detector->scanCode($code, 'SafeController.php');

        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEmpty
        $this->assertEmpty($result['violations']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertTrue
        $this->assertTrue($result['summary']['safe']);
        // @phpstan-ignore-next-line Dynamic call to static method PHPUnit\Framework\Assert::assertEquals
        $this->assertEquals(0, $result['summary']['blocking']);
    }
}
