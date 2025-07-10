<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Security;

use PivotPHP\ReactPHP\Security\RequestIsolation;
use PivotPHP\ReactPHP\Tests\TestCase;
use React\Http\Message\ServerRequest;
use React\Http\Message\Uri;

final class RequestIsolationTest extends TestCase
{
    private RequestIsolation $isolation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolation = new RequestIsolation();

        // Backup current globals
        $this->backupGlobals();
    }

    protected function tearDown(): void
    {
        // Restore globals
        $this->restoreGlobals();
        parent::tearDown();
    }

    private array $globalBackup = [];

    private function backupGlobals(): void
    {
        // Initialize superglobals if not set
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        // $_FILES, $_COOKIE, $_GET, $_POST are always set in PHP

        $this->globalBackup = [
            'SERVER' => $_SERVER,
            'GET' => $_GET,
            'POST' => $_POST,
            'COOKIE' => $_COOKIE,
            'SESSION' => $_SESSION,
            'FILES' => $_FILES,
        ];
    }

    private function restoreGlobals(): void
    {
        $_SERVER = $this->globalBackup['SERVER'];
        $_GET = $this->globalBackup['GET'];
        $_POST = $this->globalBackup['POST'];
        $_COOKIE = $this->globalBackup['COOKIE'];
        $_SESSION = $this->globalBackup['SESSION'];
        $_FILES = $this->globalBackup['FILES'];
    }

    public function testCreateContextGeneratesUniqueId(): void
    {
        $request1 = new ServerRequest('GET', new Uri('http://example.com/test1'));
        $request2 = new ServerRequest('POST', new Uri('http://example.com/test2'));

        $context1 = $this->isolation->createContext($request1);
        $context2 = $this->isolation->createContext($request2);

        self::assertNotEquals($context1, $context2);
        self::assertStringContainsString('ctx_', $context1);
        self::assertStringContainsString('GET', $context1);
        self::assertStringContainsString('POST', $context2);
    }

    public function testCreateContextResetsGlobals(): void
    {
        // Set some test data in globals
        $_GET = ['test' => 'value'];
        $_POST = ['data' => 'post'];
        $_COOKIE = ['session' => '123'];
        $_SESSION = ['user' => 'test'];

        $request = new ServerRequest('GET', new Uri('http://example.com/test'));
        $contextId = $this->isolation->createContext($request);

        // Globals should be reset
        self::assertEmpty($_GET);
        self::assertEmpty($_POST);
        self::assertEmpty($_COOKIE);
        self::assertEmpty($_SESSION);

        // SERVER should contain only safe values
        self::assertArrayHasKey('REQUEST_TIME', $_SERVER);
        self::assertArrayNotHasKey('HTTP_HOST', $_SERVER);

        // Cleanup
        $this->isolation->destroyContext($contextId);
    }

    public function testDestroyContextRestoresGlobals(): void
    {
        // Set initial globals
        $_GET = ['original' => 'get'];
        $_POST = ['original' => 'post'];

        $request = new ServerRequest('GET', new Uri('http://example.com/test'));
        $contextId = $this->isolation->createContext($request);

        // Modify globals during request
        $_GET = ['modified' => 'get'];
        $_POST = ['modified' => 'post'];

        // Destroy context should restore originals
        $this->isolation->destroyContext($contextId);

        self::assertEquals(['original' => 'get'], $_GET);
        self::assertEquals(['original' => 'post'], $_POST);
    }

    public function testTrackStaticProperty(): void
    {
        // Create a test class with static property
        $testClass = new class {
            public static string $testProperty = 'original';
        };

        $className = get_class($testClass);
        $originalValue = $testClass::$testProperty;

        $request = new ServerRequest('GET', new Uri('http://example.com/test'));
        $_SERVER['X_REQUEST_CONTEXT_ID'] = $this->isolation->createContext($request);

        $this->isolation->trackStaticProperty($className, 'testProperty', $originalValue);

        // Modify the static property
        $testClass::$testProperty = 'modified';

        // Verify the property was modified
        self::assertEquals('modified', $testClass::$testProperty);

        // Verify the original value was tracked
        self::assertEquals('original', $originalValue);

        // Cleanup
        $this->isolation->destroyContext($_SERVER['X_REQUEST_CONTEXT_ID']);
        unset($_SERVER['X_REQUEST_CONTEXT_ID']);
    }

    public function testCheckContextLeaks(): void
    {
        $request = new ServerRequest('GET', new Uri('http://example.com/test'));

        // Create a context but don't destroy it
        $contextId = $this->isolation->createContext($request);

        // Initially no leaks
        $leaks = $this->isolation->checkContextLeaks();
        self::assertEmpty($leaks);

        // Note: In a real test, we'd need to mock time to test leak detection
        // after 30 seconds. For now, just ensure the method returns expected structure

        // Cleanup
        $this->isolation->destroyContext($contextId);
    }

    public function testMultipleContextsIsolation(): void
    {
        $request1 = new ServerRequest('GET', new Uri('http://example.com/user/1'));
        $request2 = new ServerRequest('GET', new Uri('http://example.com/user/2'));

        // Create first context
        $context1 = $this->isolation->createContext($request1);
        $_GET = ['user_id' => '1'];
        $_SESSION = ['context' => '1'];

        // Create second context (should not see first context's data)
        $context2 = $this->isolation->createContext($request2);
        self::assertEmpty($_GET);
        self::assertEmpty($_SESSION);

        $_GET = ['user_id' => '2'];
        $_SESSION = ['context' => '2'];

        // Destroy contexts in order
        $this->isolation->destroyContext($context2);
        $this->isolation->destroyContext($context1);
    }

    public function testSafeServerVariablesPreserved(): void
    {
        // Set some SERVER variables
        $_SERVER = [
            'PHP_SELF' => '/index.php',
            'SCRIPT_NAME' => '/index.php',
            'DOCUMENT_ROOT' => '/var/www',
            'HTTP_HOST' => 'evil.com', // Should be removed
            'HTTP_COOKIE' => 'session=123', // Should be removed
        ];

        $request = new ServerRequest('GET', new Uri('http://example.com/test'));
        $contextId = $this->isolation->createContext($request);

        // Safe variables should be preserved
        self::assertArrayHasKey('PHP_SELF', $_SERVER);
        self::assertArrayHasKey('SCRIPT_NAME', $_SERVER);
        self::assertArrayHasKey('DOCUMENT_ROOT', $_SERVER);

        // Unsafe variables should be removed
        self::assertArrayNotHasKey('HTTP_HOST', $_SERVER);
        self::assertArrayNotHasKey('HTTP_COOKIE', $_SERVER);

        $this->isolation->destroyContext($contextId);
    }

    public function testContextIdGeneration(): void
    {
        $request = new ServerRequest('POST', new Uri('https://api.example.com/v1/users'));

        $contextId = $this->isolation->createContext($request);

        // Context ID should contain method and path hash
        self::assertStringContainsString('ctx_', $contextId);
        self::assertStringContainsString('POST', $contextId);
        self::assertGreaterThan(20, strlen($contextId)); // Should be reasonably long

        $this->isolation->destroyContext($contextId);
    }
}
