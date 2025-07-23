<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Glueful\Queue\WorkerOptions;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Psr\Log\LoggerInterface;

class ProcessFactory
{
    private LoggerInterface $logger;
    private string $basePath;
    private ?string $phpBinary = null;
    private array $environment = [];
    private array $defaultOptions = [
        'timeout' => null, // No timeout by default for queue workers
        'env' => [],
    ];

    public function __construct(
        LoggerInterface $logger,
        string $basePath,
        array $environment = []
    ) {
        $this->logger = $logger;
        $this->basePath = $basePath;
        $this->environment = $environment;
    }

    public function create(string $command, array $args = [], array $options = []): Process
    {
        $commandLine = array_merge([$command], $args);
        $options = array_merge($this->defaultOptions, $options);

        $process = new Process(
            $commandLine,
            $this->basePath,
            array_merge($this->environment, $options['env']),
            null,
            $options['timeout']
        );

        $this->logger->debug('Created process', [
            'command' => $process->getCommandLine(),
            'cwd' => $process->getWorkingDirectory(),
        ]);

        return $process;
    }

    public function createWorker(string $queue, WorkerOptions $options): WorkerProcess
    {
        $workerId = $this->generateWorkerId($queue);
        $command = $this->buildWorkerCommand($queue, $options);

        $process = new Process(
            $command,
            $this->basePath,
            $this->buildWorkerEnvironment($workerId, $options),
            null,
            null // No timeout for workers
        );

        // Set process options
        $process->setTimeout(null); // Workers run indefinitely
        $process->setIdleTimeout($options->timeout); // Idle timeout from options

        return new WorkerProcess(
            $process,
            $workerId,
            $queue,
            $options,
            $this->logger
        );
    }

    public function createPhpProcess(array $script, array $options = []): Process
    {
        $phpBinary = $this->getPhpBinary();
        $command = array_merge([$phpBinary], $script);

        return $this->create($command[0], array_slice($command, 1), $options);
    }

    private function buildWorkerCommand(string $queue, WorkerOptions $options): array
    {
        $phpBinary = $this->getPhpBinary();
        $command = [
            $phpBinary,
            'glueful',
            'queue:work',
            '--queue=' . $queue,
        ];

        // Add worker options to command
        if ($options->sleep > 0) {
            $command[] = '--sleep=' . $options->sleep;
        }

        if ($options->maxJobs > 0) {
            $command[] = '--max-jobs=' . $options->maxJobs;
        }

        if ($options->maxRuntime > 0) {
            $command[] = '--max-runtime=' . $options->maxRuntime;
        }

        if ($options->timeout > 0) {
            $command[] = '--timeout=' . $options->timeout;
        }

        if ($options->memory > 0) {
            $command[] = '--memory=' . $options->memory;
        }

        if ($options->maxAttempts > 1) {
            $command[] = '--max-attempts=' . $options->maxAttempts;
        }

        if ($options->stopWhenEmpty) {
            $command[] = '--stop-when-empty';
        }

        // Add process monitoring flags
        $command[] = '--with-monitoring';
        $command[] = '--emit-heartbeat';

        return $command;
    }

    private function buildWorkerEnvironment(string $workerId, WorkerOptions $options): array
    {
        return array_merge($this->environment, [
            'WORKER_ID' => $workerId,
            'WORKER_MEMORY_LIMIT' => (string) $options->memory,
            'WORKER_TIMEOUT' => (string) $options->timeout,
            'WORKER_ENABLE_MONITORING' => '1',
        ]);
    }

    private function generateWorkerId(string $queue): string
    {
        return sprintf(
            '%s-%s-%s',
            $queue,
            gethostname() ?: 'unknown',
            uniqid()
        );
    }

    private function getPhpBinary(): string
    {
        if ($this->phpBinary === null) {
            $finder = new PhpExecutableFinder();
            $this->phpBinary = $finder->find();

            if ($this->phpBinary === false) {
                throw new \RuntimeException('Unable to find PHP binary');
            }
        }

        return $this->phpBinary;
    }

    public function setPhpBinary(string $binary): void
    {
        $this->phpBinary = $binary;
    }

    public function setEnvironment(array $environment): void
    {
        $this->environment = $environment;
    }

    public function addEnvironmentVariable(string $name, string $value): void
    {
        $this->environment[$name] = $value;
    }

    public function setDefaultOptions(array $options): void
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);
    }

    public function createBatchWorker(array $queues, WorkerOptions $options): WorkerProcess
    {
        $workerId = $this->generateWorkerId(implode(',', $queues));
        $command = $this->buildWorkerCommand(implode(',', $queues), $options);

        $process = new Process(
            $command,
            $this->basePath,
            $this->buildWorkerEnvironment($workerId, $options),
            null,
            null
        );

        $process->setTimeout(null);
        $process->setIdleTimeout($options->timeout);

        return new WorkerProcess(
            $process,
            $workerId,
            implode(',', $queues),
            $options,
            $this->logger
        );
    }

    public function createSchedulerProcess(): Process
    {
        return $this->createPhpProcess([
            'glueful',
            'schedule:run',
            '--no-interaction',
        ]);
    }

    public function createMaintenanceProcess(string $command, array $args = []): Process
    {
        return $this->createPhpProcess(
            array_merge(['glueful', $command], $args),
            ['timeout' => 300] // 5 minute timeout for maintenance tasks
        );
    }
}
