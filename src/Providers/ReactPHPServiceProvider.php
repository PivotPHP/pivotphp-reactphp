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
        $this->app->singleton(LoopInterface::class, static function (): LoopInterface {
            return Loop::get();
        });
    }

    private function registerBridges(): void
    {
        $this->app->singleton(RequestBridge::class, static function (ContainerInterface $container): RequestBridge {
            return new RequestBridge();
        });

        $this->app->singleton(ResponseBridge::class, static function (): ResponseBridge {
            return new ResponseBridge();
        });
    }

    private function registerServer(): void
    {
        $this->app->singleton(ReactServer::class, static function (ContainerInterface $container): ReactServer {
            return new ReactServer(
                $container->get('app'),
                $container->get(LoopInterface::class),
                $container->has('logger') ? $container->get('logger') : null,
                $container->get('config')->get('reactphp.server', [])
            );
        });
    }

    private function registerCommands(): void
    {
        if ($this->app->has('console')) {
            $console = $this->app->make('console');
            $console->add(new ServeCommand($this->app));
        }
    }

    private function registerMiddleware(): void
    {
        // TODO: Implement middleware extension when Application supports it
        // $this->app->extend(
        //     'middleware.global',
        //     static function (array $middleware, ContainerInterface $container): array {
        //         $reactMiddleware = $container->get('config')->get('reactphp.middleware', []);
        //         return array_merge($middleware, $reactMiddleware);
        //     }
        // );
    }

    private function publishConfiguration(): void
    {
        if (method_exists($this->app, 'publish')) {
            $this->app->publish([
                __DIR__ . '/../../config/reactphp.php' => 'config/reactphp.php',
            ], 'reactphp-config');
        }
    }

    private function registerEventListeners(): void
    {
        $events = $this->app->make('events');

        $events->listen('server.starting', static function (ContainerInterface $container): void {
            $logger = $container->get('logger');
            $logger->info('ReactPHP server is starting...', [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
            ]);
        });

        $events->listen('server.started', static function (ContainerInterface $container, string $address): void {
            $logger = $container->get('logger');
            $logger->info('ReactPHP server started successfully', [
                'address' => $address,
                'pid' => getmypid(),
            ]);
        });

        $events->listen('server.stopping', static function (ContainerInterface $container): void {
            $logger = $container->get('logger');
            $logger->info('ReactPHP server is shutting down...');
        });
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
