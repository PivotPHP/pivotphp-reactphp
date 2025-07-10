<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Providers;

use PivotPHP\Core\Providers\ServiceProvider;
use PivotPHP\ReactPHP\Bridge\RequestBridge;
use PivotPHP\ReactPHP\Bridge\ResponseBridge;
use PivotPHP\ReactPHP\Commands\ServeCommand;
use PivotPHP\ReactPHP\Server\ReactServer;
use Psr\Container\ContainerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use PivotPHP\ReactPHP\Security\RequestIsolation;
use PivotPHP\ReactPHP\Security\BlockingCodeDetector;
use PivotPHP\ReactPHP\Security\MemoryGuard;
use PivotPHP\ReactPHP\Security\GlobalStateSandbox;

final class ReactPHPServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerEventLoop();
        $this->registerBridges();
        $this->registerSecurityComponents();
        $this->registerServer();
        $this->registerCommands();
        $this->registerMiddleware();
    }

    public function boot(): void
    {
        $this->publishConfiguration();
        $this->performSecurityChecks();
        $this->initializeSecurityMonitoring();
        // TODO: Implement event listeners when EventDispatcher supports it
        // $this->registerEventListeners();
    }

    private function registerEventLoop(): void
    {
        $this->app->getContainer()->singleton(LoopInterface::class, static function (): LoopInterface {
            return Loop::get();
        });

        // Also bind to common aliases
        $this->app->getContainer()->alias('loop', LoopInterface::class);
        $this->app->getContainer()->alias('reactphp.loop', LoopInterface::class);
    }

    private function registerBridges(): void
    {
        $this->app->getContainer()->singleton(
            RequestBridge::class,
            static function (ContainerInterface $container): RequestBridge {
                return new RequestBridge();
            }
        );

        $this->app->getContainer()->singleton(ResponseBridge::class, static function (): ResponseBridge {
            return new ResponseBridge();
        });

        // Register aliases
        $this->app->getContainer()->alias('reactphp.request.bridge', RequestBridge::class);
        $this->app->getContainer()->alias('reactphp.response.bridge', ResponseBridge::class);
    }

    private function registerServer(): void
    {
        $this->app->getContainer()->singleton(
            ReactServer::class,
            function (ContainerInterface $container): ReactServer {
                $loop = $container->get(LoopInterface::class);
                $logger = $container->has('logger') ? $container->get('logger') : null;
                $config = $this->app->getConfig()->get('reactphp.server', []);

                return new ReactServer(
                    $this->app,
                    $loop instanceof LoopInterface ? $loop : null,
                    $logger instanceof \Psr\Log\LoggerInterface ? $logger : null,
                    is_array($config) ? $config : []
                );
            }
        );

        // Register alias
        $this->app->getContainer()->alias('reactphp.server', ReactServer::class);
    }

    private function registerSecurityComponents(): void
    {
        // Request Isolation
        $this->app->getContainer()->singleton(RequestIsolation::class, function () {
            return new RequestIsolation();
        });

        // Blocking Code Detector
        $this->app->getContainer()->singleton(BlockingCodeDetector::class, function () {
            return new BlockingCodeDetector();
        });

        // Memory Guard
        $this->app->getContainer()->singleton(MemoryGuard::class, function (ContainerInterface $container) {
            $config = (array) $this->app->getConfig()->get('reactphp.memory_guard', []);
            $logger = $container->has('logger') ? $container->get('logger') : null;

            $loop = $container->get(LoopInterface::class);

            return new MemoryGuard(
                $loop instanceof LoopInterface ? $loop : Loop::get(),
                $config,
                $logger instanceof \Psr\Log\LoggerInterface ? $logger : null
            );
        });

        // Global State Sandbox
        $this->app->getContainer()->singleton(GlobalStateSandbox::class, function () {
            $sandbox = new GlobalStateSandbox();

            // Enable strict mode in production
            if ($this->app->getConfig()->get('app.env') === 'production') {
                $sandbox->enableStrictMode();
            }

            return $sandbox;
        });

        // Register aliases
        $this->app->getContainer()->alias('reactphp.security.isolation', RequestIsolation::class);
        $this->app->getContainer()->alias('reactphp.security.detector', BlockingCodeDetector::class);
        $this->app->getContainer()->alias('reactphp.security.memory', MemoryGuard::class);
        $this->app->getContainer()->alias('reactphp.security.sandbox', GlobalStateSandbox::class);
    }

    private function registerCommands(): void
    {
        // For now, we'll just register the command directly
        // PivotPHP doesn't have extend method in container

        // Register the command factory
        $this->app->getContainer()->bind(ServeCommand::class, function (ContainerInterface $container) {
            return new ServeCommand($container);
        });
    }

    private function registerMiddleware(): void
    {
        // Register ReactPHP-specific middleware aliases
        $middlewareAliases = [
            'reactphp.streaming' => \React\Http\Middleware\StreamingRequestMiddleware::class,
            'reactphp.body-buffer' => \React\Http\Middleware\RequestBodyBufferMiddleware::class,
            'reactphp.limit-concurrent' => \React\Http\Middleware\LimitConcurrentRequestsMiddleware::class,
        ];

        foreach ($middlewareAliases as $alias => $class) {
            if (!$this->app->getContainer()->has($alias)) {
                $this->app->getContainer()->bind($alias, $class);
            }
        }

        // Add ReactPHP middleware to the global stack if configured
        $reactMiddleware = $this->app->getConfig()->get('reactphp.middleware', []);
        if (is_array($reactMiddleware)) {
            foreach ($reactMiddleware as $middleware) {
                $this->app->use($middleware);
            }
        }
    }

    private function publishConfiguration(): void
    {
        // Load ReactPHP configuration
        $configPath = __DIR__ . '/../../config/reactphp.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            foreach ($config as $key => $value) {
                $this->app->getConfig()->set("reactphp.{$key}", $value);
            }
        }
    }

    /**
     * @phpstan-ignore-next-line
     */
    private function registerEventListeners(): void
    {
        if ($this->app->getContainer()->has('events')) {
            $events = $this->app->make('events');

            if (is_object($events) && method_exists($events, 'listen')) {
                $events->listen('server.starting', function (ContainerInterface $container): void {
                    if ($container->has('logger')) {
                        $logger = $container->get('logger');
                        if ($logger !== null && is_object($logger) && method_exists($logger, 'info')) {
                            $logger->info('ReactPHP server is starting...', [
                                'php_version' => PHP_VERSION,
                                'memory_limit' => ini_get('memory_limit'),
                            ]);
                        }
                    }
                });
            }

            if (is_object($events) && method_exists($events, 'listen')) {
                $events->listen('server.started', function (ContainerInterface $container, string $address): void {
                    if ($container->has('logger')) {
                        $logger = $container->get('logger');
                        if (is_object($logger) && method_exists($logger, 'info')) {
                            $logger->info('ReactPHP server started successfully', [
                            'address' => $address,
                            'pid' => getmypid(),
                            ]);
                        }
                    }
                });

                $events->listen('server.stopping', function (ContainerInterface $container): void {
                    if ($container->has('logger')) {
                        $logger = $container->get('logger');
                        if (is_object($logger) && method_exists($logger, 'info')) {
                            $logger->info('ReactPHP server is shutting down...');
                        }
                    }
                });
            }
        }
    }

    public function provides(): array
    {
        return [
            LoopInterface::class,
            ReactServer::class,
            RequestBridge::class,
            ResponseBridge::class,
            RequestIsolation::class,
            BlockingCodeDetector::class,
            MemoryGuard::class,
            GlobalStateSandbox::class,
        ];
    }

    private function performSecurityChecks(): void
    {
        $config = $this->app->getConfig();

        // Check for dangerous settings
        if ($config->get('app.debug') === true && $config->get('app.env') === 'production') {
            trigger_error(
                'ReactPHP: Debug mode should not be enabled in production',
                E_USER_WARNING
            );
        }

        // Check PHP configuration
        $this->checkPhpConfiguration();

        // Scan for blocking code if enabled
        if ($config->get('reactphp.security.scan_blocking_code', true) === true) {
            $this->scanForBlockingCode();
        }
    }

    private function checkPhpConfiguration(): void
    {
        // Check for problematic PHP settings
        $issues = [];

        if (ini_get('max_execution_time') != 0) {
            $issues[] = 'max_execution_time should be 0 for ReactPHP';
        }

        if (ini_get('memory_limit') !== '-1' && $this->parseBytes(ini_get('memory_limit')) < 256 * 1024 * 1024) {
            $issues[] = 'memory_limit should be at least 256M for ReactPHP';
        }

        if ($issues !== []) {
            $logger = $this->app->getContainer()->has('logger')
                ? $this->app->getContainer()->get('logger')
                : null;

            foreach ($issues as $issue) {
                if ($logger !== null && is_object($logger) && method_exists($logger, 'warning')) {
                    $logger->warning('ReactPHP configuration issue: ' . $issue);
                } else {
                    trigger_error('ReactPHP: ' . $issue, E_USER_WARNING);
                }
            }
        }
    }

    private function scanForBlockingCode(): void
    {
        $detector = $this->app->make(BlockingCodeDetector::class);
        if (!$detector instanceof BlockingCodeDetector) {
            return;
        }
        $scanPaths = (array) $this->app->getConfig()->get('reactphp.security.scan_paths', [
            'app',
            'src',
        ]);

        $violations = [];

        foreach ($scanPaths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $fullPath = $this->app->basePath($path);
            if (is_dir($fullPath)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath)
                );

                foreach ($files as $file) {
                    if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                        $result = $detector->scanFile($file->getPathname());
                        if ($result['violations'] !== []) {
                            $violations = array_merge($violations, $result['violations']);
                        }
                    }
                }
            }
        }

        if ($violations !== []) {
            $logger = $this->app->getContainer()->has('logger')
                ? $this->app->getContainer()->get('logger')
                : null;

            if ($logger !== null && is_object($logger) && method_exists($logger, 'warning')) {
                $logger->warning('Blocking code detected', [
                    'violations' => count($violations),
                    'details' => $violations,
                ]);
            }
        }
    }

    private function initializeSecurityMonitoring(): void
    {
        // Start memory monitoring
        $memoryGuard = $this->app->make(MemoryGuard::class);
        if ($memoryGuard instanceof MemoryGuard) {
            $memoryGuard->startMonitoring();

            // Register memory leak callback
            $memoryGuard->onMemoryLeak(function (array $leak) {
                $logger = $this->app->getContainer()->has('logger')
                ? $this->app->getContainer()->get('logger')
                : null;

                if ($logger !== null && is_object($logger) && method_exists($logger, 'error')) {
                    $logger->error('Memory leak detected', $leak);
                }

            // Trigger event if event system is available
                if ($this->app->getContainer()->has('events')) {
                    $events = $this->app->make('events');
                    if (is_object($events) && method_exists($events, 'dispatch')) {
                        $events->dispatch('reactphp.memory_leak', $leak);
                    }
                }
            });
        }
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
