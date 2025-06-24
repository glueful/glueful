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
 * - Configuration validation with helpful error messages
 * - Progress indicators for documentation generation
 * - Interactive confirmation prompts
 * - Enhanced output formatting with status information
 * - Better error handling and troubleshooting tips
 * @package Glueful\Console\Commands\Generate
 */
#[AsCommand(
    name: 'generate:api-docs',
    description: 'Generate API documentation from definitions'
)]
class ApiDocsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Generate API documentation from definitions')
             ->setHelp('This command generates comprehensive API documentation using existing API definitions ' .
                      'and route information.')
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force generation of new documentation, even if manual files exist'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');

        try {
            // Check if documentation generation is enabled
            if (!$this->validateDocumentationConfig()) {
                return self::FAILURE;
            }

            $this->info('Initializing API Documentation Generator...');
            $generator = new ApiDefinitionGenerator(true);

            // Display generation information
            $this->displayGenerationInfo($force);

            // Confirm if not forced
            if (!$force && !$this->confirmDocumentationGeneration()) {
                $this->info('API documentation generation cancelled.');
                return self::SUCCESS;
            }

            // Generate documentation with progress indication
            $this->generateDocumentation($generator, $force);

            $this->success('API documentation generated successfully!');
            $this->displayDocumentationResults();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to generate API documentation: ' . $e->getMessage());
            $this->displayTroubleshootingTips();
            return self::FAILURE;
        }
    }

    private function validateDocumentationConfig(): bool
    {
        $docsEnabled = config('app.docs_enabled', false);

        if (!$docsEnabled) {
            $this->error('API documentation generation is disabled in configuration.');
            $this->line('');
            $this->info('To enable API documentation generation:');
            $this->line('1. Set APP_DOCS_ENABLED=true in your .env file');
            $this->line('2. Or update config/app.php to set docs_enabled => true');
            $this->line('3. Ensure you are not in production environment');
            return false;
        }

        return true;
    }

    private function displayGenerationInfo(bool $force): void
    {
        $this->info('Documentation Generation Settings:');

        $settings = [
            ['Configuration', 'API docs enabled: ' . (config('app.docs_enabled') ? 'Yes' : 'No')],
            ['Environment', config('app.env', 'unknown')],
            ['Force Overwrite', $force ? 'Yes' : 'No'],
            ['Output Format', 'JSON/HTML'],
        ];

        $this->table(['Setting', 'Value'], $settings);
    }

    private function confirmDocumentationGeneration(): bool
    {
        $this->warning('This will generate new API documentation files.');
        $this->line('Existing manual documentation may be overwritten.');

        return $this->confirm('Continue with API documentation generation?', true);
    }

    private function generateDocumentation(ApiDefinitionGenerator $generator, bool $force): void
    {
        $this->info('Generating API documentation...');
        $this->line('This may take a moment depending on the size of your API...');

        // Generate the documentation
        $generator->generateApiDocs($force);

        $this->line('✓ API documentation files generated');
    }

    private function displayDocumentationResults(): void
    {
        $this->line('');
        $this->info('Documentation generation completed successfully!');
        $this->line('');

        $this->info('Generated files:');
        $this->line('✓ API definition JSON files');
        $this->line('✓ OpenAPI/Swagger documentation');
        $this->line('✓ HTML documentation (if configured)');

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Review generated documentation files');
        $this->line('2. Customize documentation as needed');
        $this->line('3. Deploy documentation to your web server');
        $this->line('4. Share API documentation with your team');

        // Show documentation access information
        $this->displayDocumentationAccess();
    }

    private function displayDocumentationAccess(): void
    {
        $this->line('');
        $this->info('Accessing your API documentation:');

        if (config('app.env') === 'development') {
            $baseUrl = 'http://localhost:8000';
            $this->line("• Development: {$baseUrl}/docs");
            $this->line("• API Explorer: {$baseUrl}/api-explorer");
        }

        $this->line('• Check your documentation output directory');
        $this->line('• Ensure web server has access to documentation files');
    }

    private function displayTroubleshootingTips(): void
    {
        $this->line('');
        $this->warning('Troubleshooting tips:');
        $this->line('1. Ensure API definitions exist (run generate:api-definitions first)');
        $this->line('2. Check that docs_enabled is true in configuration');
        $this->line('3. Verify file permissions for output directories');
        $this->line('4. Make sure database connections are working');
        $this->line('5. Check logs for detailed error information');
    }
}
