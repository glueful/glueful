<?php

namespace Glueful\App\Console\Commands;

use Glueful\App\Console\Command;
use Glueful\Api\JsonGenerator;

/**
 * JSON Generator Command
 * 
 * Generates JSON definitions for API endpoints and documentation.
 * Supports database schema to API definition conversion.
 */
class GenerateJsonCommand extends Command
{
    /**
     * Get command name
     * 
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'generate:json';
    }

    /**
     * Get command description
     * 
     * @return string Brief command description
     */
    public function getDescription(): string 
    {
        return 'Generate JSON definitions and API documentation';
    }

    /**
     * Get detailed help
     * 
     * @return string Command usage instructions
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
     * Execute generator command
     * 
     * Processes arguments and generates requested JSON files.
     * 
     * @param array $args Command line arguments
     */
    public function execute(array $args = []): void
    {
        // Show help if requested
        if (in_array('-h', $args) || in_array('--help', $args)) {
            $this->info($this->getHelp());
            return;
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
            return;
        }

        $generator = new JsonGenerator(true);
        
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
                $this->info("Generated JSON for all databases");
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
     * Parse command options
     * 
     * Processes command line arguments into options array.
     * 
     * @param array $args Raw command arguments
     * @return array Parsed options
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