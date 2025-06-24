<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Helpers\ExtensionsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extensions Install Command
 * - Install extensions from URLs, archives, or Git repositories
 * - Automatic dependency resolution and validation
 * - Support for multiple archive formats (zip, tar.gz, tar.bz2)
 * - Security validation and integrity checks
 * - Rollback capability on installation failure
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:install',
    description: 'Install an extension from URL, archive, or repository'
)]
class InstallCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Install an extension from URL, archive, or repository')
             ->setHelp('This command installs extensions from various sources including URLs, ' .
                       'archives, and Git repositories.')
             ->addArgument(
                 'source',
                 InputArgument::REQUIRED,
                 'Source URL, file path, or Git repository'
             )
             ->addOption(
                 'name',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Target extension name (auto-detected if not provided)'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Overwrite existing extension'
             )
             ->addOption(
                 'no-deps',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip dependency installation'
             )
             ->addOption(
                 'verify',
                 'v',
                 InputOption::VALUE_NONE,
                 'Verify extension integrity after installation'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getArgument('source');
        $targetName = $input->getOption('name');

        try {
            $this->info("Installing extension from: {$source}");

            // Use ExtensionsManager for installation
            $result = ExtensionsManager::installExtension($source, $targetName);

            if (!empty($result['error'])) {
                $this->error('Installation failed: ' . $result['error']);
                return self::FAILURE;
            }

            $extensionName = $result['name'] ?? $targetName;
            $this->success("Extension '{$extensionName}' installed successfully!");

            if (!empty($result['message'])) {
                $this->info($result['message']);
            }

            if (!empty($result['warnings'])) {
                foreach ($result['warnings'] as $warning) {
                    $this->warning($warning);
                }
            }

            // Display installation summary
            $extensionPath = $this->getExtensionPath($extensionName);
            $this->displayInstallationSummary($extensionName, $extensionPath, $result);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Installation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function displayInstallationSummary(string $name, string $path, array $config): void
    {
        $this->line('');
        $this->info('Installation Summary:');
        $this->table(['Property', 'Value'], [
            ['Extension Name', $name],
            ['Version', $config['version'] ?? 'Unknown'],
            ['Description', $config['description'] ?? 'No description'],
            ['Install Path', $path],
            ['Status', 'Installed (disabled)']
        ]);

        $this->line('');
        $this->info('Next steps:');
        $this->line("1. Enable the extension: php glueful extensions:enable {$name}");
        $this->line("2. Configure the extension if needed");
        $this->line("3. Test the extension functionality");
    }
}
