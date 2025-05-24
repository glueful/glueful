<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;

/**
 * Development Server Command
 *
 * Starts a local development server for testing
 */
class ServeCommand extends Command
{
    public function getName(): string
    {
        return 'serve';
    }

    public function getDescription(): string
    {
        return 'Start the Glueful development server';
    }

    public function getHelp(): string
    {
        return <<<HELP
Start development server:

Usage:
  php glueful serve [options]

Options:
  --port=PORT  Port to run on (default: 8000)
  --host=HOST  Host to bind to (default: localhost)

Examples:
  php glueful serve
  php glueful serve --port=3000
  php glueful serve --host=0.0.0.0 --port=8080
HELP;
    }

    public function execute(array $args = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $port = $this->getOption($args, 'port', '8000');
        $host = $this->getOption($args, 'host', 'localhost');

        // Validate port
        if (!is_numeric($port) || $port < 1 || $port > 65535) {
            $this->error('Invalid port number. Must be between 1 and 65535.');
            return 1;
        }

        // Check if port is available
        $socket = @fsockopen($host, (int)$port, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            $this->error("Port $port is already in use.");
            return 1;
        }

        $apiDir = dirname(__DIR__, 2);

        if (!is_dir($apiDir)) {
            $this->error('API directory not found. Expected: ' . $apiDir);
            return 1;
        }

        $this->info("🚀 Starting Glueful development server...");
        $this->line("Server running at: http://$host:$port");
        $this->line("Document root: $apiDir");
        $this->line("Press Ctrl+C to stop the server");
        $this->line("");

        // Set environment to development
        putenv('APP_ENV=development');

        // Start the server
        $command = sprintf(
            'php -S %s:%s -t %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($apiDir)
        );

        passthru($command);
        return 0;
    }

    /**
     * Get option value from arguments
     */
    private function getOption(array $args, string $option, string $default = ''): string
    {
        foreach ($args as $arg) {
            if (strpos($arg, "--$option=") === 0) {
                return substr($arg, strlen("--$option="));
            }
        }
        return $default;
    }
}
