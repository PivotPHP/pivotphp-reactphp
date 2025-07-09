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

final class ReactPHPServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerEventLoop();
        $this->registerBridges();
        $this->registerServer();
        $this->registerCommands();
        $this->registerMiddleware();
    }

    public function boot(): void
    {
        $this->publishConfiguration();
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
        $this->app->getContainer()->singleton(RequestBridge::class, static function (ContainerInterface $container): RequestBridge {
            return new RequestBridge();
        });

        $this->app->getContainer()->singleton(ResponseBridge::class, static function (): ResponseBridge {
            return new ResponseBridge();
        });
        
        // Register aliases
        $this->app->getContainer()->alias('reactphp.request.bridge', RequestBridge::class);
        $this->app->getContainer()->alias('reactphp.response.bridge', ResponseBridge::class);
    }

    private function registerServer(): void
    {
        $this->app->getContainer()->singleton(ReactServer::class, function (ContainerInterface $container): ReactServer {
            return new ReactServer(
                $this->app,
                $container->get(LoopInterface::class),
                $container->has('logger') ? $container->get('logger') : null,
                $this->app->getConfig()->get('reactphp.server', [])
            );
        });
        
        // Register alias
        $this->app->getContainer()->alias('reactphp.server', ReactServer::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->getContainer()->has('console.commands')) {
            $this->app->getContainer()->extend('console.commands', function (array $commands) {
                $commands[] = ServeCommand::class;
                return $commands;
            });
        }
        
        // Register the command factory
        $this->app->getContainer()->bind(ServeCommand::class, function () {
            return new ServeCommand($this->app);
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
        foreach ($reactMiddleware as $middleware) {
            $this->app->use($middleware);
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

    private function registerEventListeners(): void
    {
        if ($this->app->getContainer()->has('events')) {
            $events = $this->app->make('events');

            $events->listen('server.starting', function (ContainerInterface $container): void {
                if ($container->has('logger')) {
                    $logger = $container->get('logger');
                    $logger->info('ReactPHP server is starting...', [
                        'php_version' => PHP_VERSION,
                        'memory_limit' => ini_get('memory_limit'),
                    ]);
                }
            });

            $events->listen('server.started', function (ContainerInterface $container, string $address): void {
                if ($container->has('logger')) {
                    $logger = $container->get('logger');
                    $logger->info('ReactPHP server started successfully', [
                        'address' => $address,
                        'pid' => getmypid(),
                    ]);
                }
            });

            $events->listen('server.stopping', function (ContainerInterface $container): void {
                if ($container->has('logger')) {
                    $logger = $container->get('logger');
                    $logger->info('ReactPHP server is shutting down...');
                }
            });
        }
    }

    public function provides(): array
    {
        return [
            LoopInterface::class,
            ReactServer::class,
            RequestBridge::class,
            ResponseBridge::class,
        ];
    }
}
