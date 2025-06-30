<?php

namespace Glueful\Console\Commands\Event;

use Glueful\Console\BaseCommand;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Event Listener Create Command
 * Creates new event listener classes with proper structure:
 * - Interactive listener name validation
 * - Template-based file generation with event binding
 * - Support for event-specific listener methods
 * - Proper PSR-4 autoloading structure
 * - FileFinder and FileManager integration for safe file operations
 * @package Glueful\Console\Commands\Event
 */
#[AsCommand(
    name: 'event:listener',
    description: 'Create a new event listener class'
)]
class CreateListenerCommand extends BaseCommand
{
    private FileFinder $fileFinder;
    private FileManager $fileManager;

    protected function configure(): void
    {
        $this->setDescription('Create a new event listener class')
             ->setHelp('This command generates a new event listener class with proper structure and ' .
                       'event handling methods.')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the listener to generate (e.g., UserRegistrationListener)'
             )
             ->addOption(
                 'event',
                 'e',
                 InputOption::VALUE_OPTIONAL,
                 'The event class this listener should handle'
             )
             ->addOption(
                 'method',
                 'm',
                 InputOption::VALUE_OPTIONAL,
                 'The method name to handle the event (default: handle)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $listenerName = $input->getArgument('name');
        $eventClass = $input->getOption('event');
        $methodName = $input->getOption('method') ?? 'handle';

        // Validate listener name format
        if (!$this->isValidListenerName($listenerName)) {
            $this->error('Invalid listener name format.');
            $this->line('');
            $this->info('Listener names should use PascalCase and end with "Listener".');
            $this->line('Examples:');
            $this->line('  • UserRegistrationListener');
            $this->line('  • SecurityAlertListener');
            $this->line('  • EmailNotificationListener');

            return self::FAILURE;
        }

        try {
            $this->info(sprintf('Creating listener: %s', $listenerName));

            $listenerInfo = $this->parseListenerName($listenerName);

            // Check if listener already exists
            if ($this->listenerExists($listenerInfo['path'])) {
                $this->error(sprintf('Listener already exists at: %s', $listenerInfo['path']));
                return self::FAILURE;
            }

            $filePath = $this->createListener($listenerInfo, $eventClass, $methodName);

            $this->success('Listener created successfully!');
            $this->line('');
            $this->info(sprintf('File: %s', $filePath));
            $this->info(sprintf('Class: %s', $listenerInfo['fullClassName']));
            $this->line('');
            $this->info('Next steps:');
            $this->line('  1. Implement your event handling logic in the ' . $methodName . '() method');
            $this->line('  2. Register the listener: Event::listen(EventClass::class, ['
                . $listenerInfo['className'] . '::class, \'' . $methodName . '\'])');
            if ($eventClass) {
                $this->line('  3. Or inject as dependency in your service provider');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create listener: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Initialize required services
     */
    private function initializeServices(): void
    {
        $this->fileFinder = new FileFinder();
        $this->fileManager = new FileManager();
    }

    /**
     * Validate listener name format
     */
    private function isValidListenerName(string $name): bool
    {
        // Remove .php extension if provided
        $name = str_replace('.php', '', $name);

        // Check if class name is valid PascalCase
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Parse listener name and determine paths/namespaces
     */
    private function parseListenerName(string $listenerName): array
    {
        // Remove .php extension if provided
        $listenerName = str_replace('.php', '', $listenerName);

        // Ensure class name ends with Listener
        if (!str_ends_with($listenerName, 'Listener')) {
            $listenerName .= 'Listener';
        }

        // Build directory path using config
        $baseDir = config('app.paths.app_listeners');
        $filePath = $baseDir . DIRECTORY_SEPARATOR . $listenerName . '.php';

        // Build namespace for application listeners
        $namespace = 'App\\Events\\Listeners';
        $fullClassName = $namespace . '\\' . $listenerName;

        return [
            'className' => $listenerName,
            'namespace' => $namespace,
            'directory' => $baseDir,
            'path' => $filePath,
            'fullClassName' => $fullClassName
        ];
    }

    /**
     * Check if listener already exists
     */
    private function listenerExists(string $path): bool
    {
        return $this->fileManager->exists($path);
    }

    /**
     * Create the listener file
     */
    private function createListener(array $listenerInfo, ?string $eventClass, string $methodName): string
    {
        // Create directory if it doesn't exist using FileManager
        if (!$this->fileManager->exists($listenerInfo['directory'])) {
            if (!$this->fileManager->createDirectory($listenerInfo['directory'], 0755)) {
                throw new \RuntimeException('Failed to create directory: ' . $listenerInfo['directory']);
            }
        }

        $content = $this->generateListenerContent($listenerInfo, $eventClass, $methodName);

        // Write file using FileManager for safe operations
        if (!$this->fileManager->writeFile($listenerInfo['path'], $content)) {
            throw new \RuntimeException('Failed to write listener file: ' . $listenerInfo['path']);
        }

        return $listenerInfo['path'];
    }

    /**
     * Generate listener class content
     */
    private function generateListenerContent(array $listenerInfo, ?string $eventClass, string $methodName): string
    {
        $className = $listenerInfo['className'];
        $namespace = $listenerInfo['namespace'];

        // Determine event parameter and imports
        $eventParameter = $eventClass ? $this->getEventClassName($eventClass) . ' $event' : '$event';
        $eventImport = $eventClass ? "use {$eventClass};" : '';
        $eventTypeHint = $eventClass ? $this->getEventClassName($eventClass) : 'object';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Psr\Log\LoggerInterface;
{$eventImport}

/**
 * {$className}
 *
 * Handles events and performs business logic operations
 *
 * Usage:
 * Event::listen(EventClass::class, [{$className}::class, '{$methodName}']);
 */
class {$className}
{
    public function __construct(
        private ?LoggerInterface \$logger = null
    ) {}

    /**
     * Handle the event
     *
     * @param {$eventTypeHint} \$event The event instance
     * @return void
     */
    public function {$methodName}({$eventParameter}): void
    {
        // Implement your event handling logic here
        
        if (\$this->logger) {
            \$this->logger->info('Event handled', [
                'listener' => static::class,
                'event' => get_class(\$event),
                'timestamp' => date('c')
            ]);
        }
        
        // TODO: Add your business logic here
    }
}
PHP;
    }

    /**
     * Extract class name from full event class name
     */
    private function getEventClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return array_pop($parts);
    }
}
