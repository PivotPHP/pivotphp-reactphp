<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Commands;

use PivotPHP\ReactPHP\Server\ReactServer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ServeCommand extends Command
{
    protected static $defaultName = 'serve:reactphp';
    protected static $defaultDescription = 'Start the ReactPHP HTTP server';

    public function __construct(private ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command starts a ReactPHP HTTP server for your PivotPHP application')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'The host address to serve on', '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'The port to serve on', '8080')
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of worker processes (experimental)', '1')
            ->addOption('env', 'e', InputOption::VALUE_OPTIONAL, 'The environment to run in', 'production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $host = $input->getOption('host');
        $port = $input->getOption('port');
        $workers = (int) $input->getOption('workers');
        $env = $input->getOption('env');
        
        $address = sprintf('%s:%s', $host, $port);
        
        $io->title('PivotPHP ReactPHP Server');
        $io->text([
            sprintf('Environment: <info>%s</info>', $env),
            sprintf('PHP Version: <info>%s</info>', PHP_VERSION),
            sprintf('Memory Limit: <info>%s</info>', ini_get('memory_limit')),
            '',
        ]);
        
        if ($workers > 1) {
            $io->warning('Multi-worker mode is experimental and may not work as expected.');
            return $this->runMultiWorker($io, $address, $workers);
        }
        
        return $this->runSingleWorker($io, $address);
    }

    private function runSingleWorker(SymfonyStyle $io, string $address): int
    {
        try {
            $server = $this->container->get(ReactServer::class);
            
            $io->success(sprintf('Server running on http://%s', $address));
            $io->text('Press Ctrl+C to stop the server');
            $io->newLine();
            
            $this->container->get('events')->dispatch('server.starting', [$this->container]);
            
            $server->listen($address);
            
            $this->container->get('events')->dispatch('server.stopped', [$this->container]);
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to start server: %s', $e->getMessage()));
            
            if ($this->container->get('config')->get('app.debug', false)) {
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    private function runMultiWorker(SymfonyStyle $io, string $address, int $workers): int
    {
        $io->error('Multi-worker mode is not yet implemented.');
        $io->text('Please use --workers=1 for now.');
        
        return Command::FAILURE;
    }
}