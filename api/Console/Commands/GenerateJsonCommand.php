<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\ApiDefinitionGenerator;

/**
 * JSON Definition Generator
 * 
 * Manages API definition file generation:
 * - Creates API endpoint definitions
 * - Generates API documentation
 * - Converts database schemas
 * - Handles multiple databases
 * - Supports selective generation
 * - Validates output
 * - Maintains consistency
 * 
 * @package Glueful\Console\Commands
 */
class GenerateJsonCommand extends Command
{
    /**
     * Get Command Name
     * 
     * Returns command identifier:
     * - Used in CLI as `php glueful generate:json`
     * - Unique command name
     * - Follows naming conventions
     * 
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'generate:json';
    }

    /**
     * Get Command Description
     * 
     * Provides overview for help listings:
     * - Single line summary
     * - Shows in command lists
     * - Explains primary purpose
     * 
     * @return string Brief description
     */
    public function getDescription(): string 
    {
        return 'Generate JSON definitions and API documentation';
    }

    /**
     * Get Command Help
     * 
     * Provides detailed usage instructions:
     * - Shows command syntax
     * - Lists all options
     * - Includes usage examples
     * - Documents parameters
     * 
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  generate:json <type> [-d <database>] [-T <table>]
  generate:json -t <type> [-d <database>] [-T <table>]
  generate:json --type=<type> [--database=<name>] [--table=<name>]

Options:
  <type>                  Type of generation (api-definitions|doc)
  -t, --type=<type>      Alternative way to specify type
  -d, --database=<name>  Database name (optional)
  -T, --table=<name>     Table name (optional)
  -h, --help            Show this help message

Examples:
  php glueful generate:json api-definitions
  php glueful generate:json api-definitions -d mydb -T users
  php glueful generate:json -t api-definitions -d mydb
  php glueful generate:json --type=doc
HELP;
    }

    /**
     * Execute Generation Command
     * 
     * Handles JSON generation process:
     * - Parses command arguments
     * - Validates input options
     * - Selects generation type
     * - Processes database schemas
     * - Generates output files
     * - Reports results
     * - Handles errors
     * 
     * @param array $args Command line arguments
     * @throws \RuntimeException If generation fails
     * @return int Exit code (0 for success, non-zero for error)
     */
    public function execute(array $args = []): int
    {
        // Show help if requested
        if (in_array('-h', $args) || in_array('--help', $args)) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        // Parse arguments
        $options = $this->parseOptions($args);
        
        // Check for type as first argument if not specified with -t or --type
        if (!isset($options['type']) && isset($args[0]) && !str_starts_with($args[0], '-')) {
            $options['type'] = $args[0];
        }

        if (!isset($options['type'])) {
            $this->error("Missing type argument or option (-t, --type)");
            $this->info($this->getHelp());
            return Command::FAILURE;
        }

        $generator = new ApiDefinitionGenerator(true);
        
        if ($options['type'] === 'api-definitions') {
            $database = $options['database'] ?? null;
            $table = $options['table'] ?? null;
            
            if ($database && $table) {
                $generator->generate($database, $table);
                $this->info("Generated JSON for table: $table");
            } else if ($database) {
                $generator->generate($database);
                $this->info("Generated JSON for database: $database");
            } else {
                $generator->generate();
                $this->info("Generated JSON for all database(s)");
            }
        } else if ($options['type'] === 'doc') {
            if (\config('app.docs_enabled')) {
                $generator->generateApiDocs();
                $this->info("Generated API documentation");
            } else {
                $this->error('API documentation generation is disabled in configuration');
            }
        } else {
            $this->error("Invalid type. Must be 'api-definitions' or 'doc'");
            $this->info($this->getHelp());
        }
    }

    /**
     * Parse Command Options
     * 
     * Processes command line input:
     * - Handles short and long options
     * - Validates option values
     * - Maps option aliases
     * - Provides defaults
     * - Maintains option order
     * 
     * @param array $args Raw command arguments
     * @return array Processed options
     */
    private function parseOptions(array $args): array
    {
        $options = [];
        $map = [
            't' => 'type',
            'd' => 'database',
            'T' => 'table'
        ];
        
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            
            // Skip if it's the first argument and doesn't start with -
            if ($i === 0 && !str_starts_with($arg, '-')) {
                continue;
            }
            
            // Handle long options (--option=value)
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', $arg);
                if (count($parts) === 2) {
                    $key = ltrim($parts[0], '-');
                    $options[$key] = $parts[1];
                }
            }
            // Handle short options (-o value)
            elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $key = substr($arg, 1);
                if (isset($map[$key]) && isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
                    $options[$map[$key]] = $args[$i + 1];
                    $i++; // Skip next argument as it's the value
                }
            }
        }
        
        return $options;
    }
}