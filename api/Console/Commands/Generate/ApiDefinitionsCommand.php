<?php

namespace Glueful\Console\Commands\Generate;

use Glueful\Console\BaseCommand;
use Glueful\ApiDefinitionGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate API Definitions Command
 * - Interactive prompts for database and table selection
 * - Progress indicators for generation process
 * - Detailed validation with helpful error messages
 * - Enhanced output formatting with tables
 * - Better error handling and recovery
 * @package Glueful\Console\Commands\Generate
 */
#[AsCommand(
    name: 'generate:api-definitions',
    description: 'Generate JSON API definitions from database schema'
)]
class ApiDefinitionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Generate JSON API definitions from database schema')
             ->setHelp(
                 'This command generates API endpoint definitions by analyzing database schema and ' .
                 'creating JSON definitions.'
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
                $this->info('API definition generation cancelled.');
                return self::SUCCESS;
            }

            // Perform generation with progress indication
            $this->generateDefinitions($generator, $database, $table, $force);

            $this->success('API definitions generated successfully!');
            $this->displayGenerationResults($database, $table);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to generate API definitions: ' . $e->getMessage());
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
        $this->info('Generating API definitions...');

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
            $this->line("✓ Generated JSON definitions for table: {$table}");
        } elseif ($database) {
            $this->line("✓ Generated JSON definitions for database: {$database}");
        } else {
            $this->line('✓ Generated JSON definitions for all databases');
        }

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Review generated API definition files');
        $this->line('2. Customize definitions as needed for your API');
        $this->line('3. Generate API documentation with: generate:api-docs');
        $this->line('4. Test your API endpoints');
    }
}
