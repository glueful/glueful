<?php

namespace Glueful\Console\Commands\Generate;

use Glueful\Console\BaseCommand;
use Glueful\ApiDefinitionGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate API Documentation Command
 * Generates comprehensive OpenAPI/Swagger documentation including:
 * - Database-driven CRUD API definitions from schema analysis
 * - Route-based API definitions from OpenAPI annotations
 * - Complete swagger.json specification file
 * - Individual JSON definition files for each endpoint
 *
 * Features:
 * - Interactive prompts for database and table selection
 * - Progress indicators for generation process
 * - Detailed validation with helpful error messages
 * - Enhanced output formatting with tables
 * - Better error handling and recovery
 * @package Glueful\Console\Commands\Generate
 */
#[AsCommand(
    name: 'generate:api-definitions',
    description: 'Generate complete OpenAPI/Swagger documentation from database schema and route annotations'
)]
class ApiDefinitionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription(
            'Generate complete OpenAPI/Swagger documentation from database schema and route annotations'
        )
             ->setHelp(
                 'This command generates comprehensive API documentation including:\n' .
                 '• Database-driven CRUD endpoints from schema analysis\n' .
                 '• Route-based endpoints from OpenAPI annotations in route files\n' .
                 '• Complete swagger.json specification for API documentation\n' .
                 '• Individual JSON definition files for each endpoint\n\n' .
                 'The generated documentation can be used with API documentation tools like ' .
                 'RapiDoc, Swagger UI, or Redoc.'
             )
             ->addOption(
                 'database',
                 'd',
                 InputOption::VALUE_REQUIRED,
                 'Specific database name to generate definitions for'
             )
             ->addOption(
                 'table',
                 'T',
                 InputOption::VALUE_REQUIRED,
                 'Specific table name to generate definitions for (requires --database)'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force generation of new definitions, even if manual files exist'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = $input->getOption('database');
        $table = $input->getOption('table');
        $force = $input->getOption('force');

        // Validate table option requires database
        if ($table && !$database) {
            $this->error('Table option requires database option to be specified.');
            $this->tip('Use: --database=mydb --table=users');
            return self::FAILURE;
        }

        try {
            $this->info('Initializing API Definition Generator...');
            $generator = new ApiDefinitionGenerator(true);

            // Display generation scope
            $this->displayGenerationScope($database, $table, $force);

            // Confirm if not forced and potentially destructive
            if (!$force && !$this->confirmGeneration($database, $table)) {
                $this->info('API documentation generation cancelled.');
                return self::SUCCESS;
            }

            // Perform generation with progress indication
            $this->generateDefinitions($generator, $database, $table, $force);

            $this->success('API documentation generated successfully!');
            $this->displayGenerationResults($database, $table);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to generate API documentation: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function displayGenerationScope(?string $database, ?string $table, bool $force): void
    {
        $this->info('Generation Scope:');

        $scope = [];
        if ($database && $table) {
            $scope[] = ['Target', "Table '{$table}' in database '{$database}'"];
        } elseif ($database) {
            $scope[] = ['Target', "All tables in database '{$database}'"];
        } else {
            $scope[] = ['Target', 'All tables in all databases'];
        }

        $scope[] = ['Force Overwrite', $force ? 'Yes' : 'No'];

        $this->table(['Property', 'Value'], $scope);
    }

    private function confirmGeneration(?string $database, ?string $table): bool
    {
        if ($database && $table) {
            return $this->confirm("Generate API definitions for table '{$table}' in database '{$database}'?", true);
        } elseif ($database) {
            return $this->confirm("Generate API definitions for all tables in database '{$database}'?", true);
        } else {
            return $this->confirm('Generate API definitions for all databases and tables?', false);
        }
    }

    private function generateDefinitions(
        ApiDefinitionGenerator $generator,
        ?string $database,
        ?string $table,
        bool $force
    ): void {
        $this->info('Generating API documentation...');

        if ($database && $table) {
            $this->line("Processing table: {$table}");
            $generator->generate($database, $table, $force);
        } elseif ($database) {
            $this->line("Processing database: {$database}");
            $generator->generate($database, null, $force);
        } else {
            $this->line('Processing all databases...');
            $generator->generate(null, null, $force);
        }
    }

    private function displayGenerationResults(?string $database, ?string $table): void
    {
        $this->line('');
        $this->info('Generation completed successfully!');

        if ($database && $table) {
            $this->line("✓ Generated API documentation for table: {$table}");
        } elseif ($database) {
            $this->line("✓ Generated API documentation for database: {$database}");
        } else {
            $this->line('✓ Generated API documentation for all databases');
        }

        $this->line('✓ Processed route-based API annotations');
        $this->line('✓ Created individual endpoint definitions');
        $this->line('✓ Generated swagger.json specification');

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Review generated swagger.json and API definition files');
        $this->line('2. Customize route annotations as needed for your API');

        // Build documentation URL dynamically from configuration
        $apiBaseUrl = rtrim(config('app.paths.api_base_url'), '/');
        $apiVersion = config('app.api_version');
        $docsUrlWithApi = $apiBaseUrl . '/' . $apiVersion . '/docs';

        $this->line("3. Visit the API documentation with api explorer at {$docsUrlWithApi}");
        $this->line('4. Test your API endpoints');
    }
}
