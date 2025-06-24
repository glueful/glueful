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
 * Extensions Enable Command
 * - Interactive confirmation with dependency checking
 * - Validation of extension requirements and compatibility
 * - Progress indicators for enabling process
 * - Detailed error messages with troubleshooting tips
 * - Rollback capability on failure
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:enable',
    description: 'Enable an installed extension'
)]
class EnableCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Enable an installed extension')
             ->setHelp(
                 'This command enables an extension, making it active in the application with dependency validation.'
             )
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the extension to enable'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force enable without confirmation or dependency checks'
             )
             ->addOption(
                 'check-dependencies',
                 'd',
                 InputOption::VALUE_NONE,
                 'Perform thorough dependency validation before enabling'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        $force = $input->getOption('force');
        $checkDependencies = $input->getOption('check-dependencies');

        try {
            $this->info("Enabling extension: {$extensionName}");

            $extensionsManager = new ExtensionsManager();

            // Validate extension exists
            if (!$this->validateExtensionExists($extensionsManager, $extensionName)) {
                return self::FAILURE;
            }

            // Check if already enabled
            if ($this->isExtensionEnabled($extensionsManager, $extensionName)) {
                $this->warning("Extension '{$extensionName}' is already enabled.");
                return self::SUCCESS;
            }

            // Dependency validation
            if ($checkDependencies || !$force) {
                if (!$this->validateDependencies($extensionsManager, $extensionName)) {
                    return self::FAILURE;
                }
            }

            // Confirm action if not forced
            if (!$force && !$this->confirmEnable($extensionName)) {
                $this->info('Extension enable operation cancelled.');
                return self::SUCCESS;
            }

            // Enable the extension
            $result = ExtensionsManager::enableExtension($extensionName);

            if (!empty($result['error'])) {
                $this->error($result['error']);
                return self::FAILURE;
            }

            $this->success("Extension '{$extensionName}' enabled successfully!");
            $this->displayNextSteps($extensionName);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to enable extension '{$extensionName}': " . $e->getMessage());
            $this->displayTroubleshootingTips();
            return self::FAILURE;
        }
    }

    private function validateExtensionExists(ExtensionsManager $manager, string $name): bool
    {
        $extension = $this->findExtension($manager, $name);

        if (!$extension) {
            $this->error("Extension '{$name}' not found.");

            // Suggest similar extensions
            $extensions = $manager->getLoadedExtensions();
            $available = array_column($extensions, 'name');
            $suggestions = $this->findSimilarExtensions($name, $available);

            if (!empty($suggestions)) {
                $this->line('');
                $this->info('Did you mean:');
                foreach ($suggestions as $suggestion) {
                    $this->line("• {$suggestion}");
                }
            } else {
                $this->line('');
                $this->info('Available extensions:');
                foreach ($available as $extension) {
                    $this->line("• {$extension}");
                }
            }

            return false;
        }

        return true;
    }


    private function validateDependencies(ExtensionsManager $manager, string $name): bool
    {
        $this->info('Validating dependencies...');

        $extension = $this->findExtension($manager, $name);
        $dependencies = $extension['metadata']['dependencies']['extensions'] ?? [];

        if (empty($dependencies)) {
            $this->line('✓ No dependencies required');
            return true;
        }

        $missingDeps = [];
        $disabledDeps = [];

        foreach ($dependencies as $depName) {
            $depExtension = $this->findExtension($manager, $depName);
            if (!$depExtension) {
                $missingDeps[] = $depName;
            } elseif (!($depExtension['metadata']['enabled'] ?? false)) {
                $disabledDeps[] = $depName;
            }
        }

        if (!empty($missingDeps)) {
            $this->error('Missing required dependencies:');
            foreach ($missingDeps as $dep) {
                $this->line("• {$dep}");
            }
            return false;
        }

        if (!empty($disabledDeps)) {
            $this->warning('Required dependencies are disabled:');
            foreach ($disabledDeps as $dep) {
                $this->line("• {$dep}");
            }

            if ($this->confirm('Enable required dependencies automatically?', true)) {
                foreach ($disabledDeps as $dep) {
                    $this->line("Enabling dependency: {$dep}");
                    $manager->enableExtension($dep);
                }
            } else {
                return false;
            }
        }

        $this->success('✓ All dependencies validated');
        return true;
    }

    private function confirmEnable(string $name): bool
    {
        return $this->confirm("Enable extension '{$name}'?", true);
    }


    private function displayNextSteps(string $name): void
    {
        $this->line('');
        $this->info('Next steps:');
        $this->line("1. Extension '{$name}' is now active");
        $this->line('2. Check extension documentation for configuration options');
        $this->line('3. Restart application if required by the extension');
        $this->line('4. Test extension functionality');
    }

    private function displayTroubleshootingTips(): void
    {
        $this->line('');
        $this->warning('Troubleshooting tips:');
        $this->line('1. Verify extension exists in extensions/ directory');
        $this->line('2. Check extension configuration file (extension.json)');
        $this->line('3. Ensure all dependencies are installed');
        $this->line('4. Check file permissions');
        $this->line('5. Review application logs for detailed errors');
    }
}
