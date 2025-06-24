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
 * Extensions Disable Command
 * - Interactive confirmation with dependent extensions checking
 * - Validation of extension dependencies to prevent breaking changes
 * - Progress indicators for disabling process
 * - Detailed warnings about affected functionality
 * - Safe disable with rollback capability
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:disable',
    description: 'Disable an active extension'
)]
class DisableCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Disable an active extension')
             ->setHelp('This command disables an extension, making it inactive with dependent extension validation.')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the extension to disable'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force disable without confirmation or dependent checks'
             )
             ->addOption(
                 'check-dependents',
                 'd',
                 InputOption::VALUE_NONE,
                 'Check for extensions that depend on this extension'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        $force = $input->getOption('force');
        $checkDependents = $input->getOption('check-dependents');

        try {
            $this->info("Disabling extension: {$extensionName}");

            $extensionsManager = new ExtensionsManager();

            // Validate extension exists and is enabled
            if (!$this->validateExtensionCanBeDisabled($extensionsManager, $extensionName)) {
                return self::FAILURE;
            }

            // Check for dependent extensions
            if ($checkDependents || !$force) {
                if (!$this->validateDependents($extensionsManager, $extensionName, $force)) {
                    return self::FAILURE;
                }
            }

            // Confirm action if not forced
            if (!$force && !$this->confirmDisable($extensionName)) {
                $this->info('Extension disable operation cancelled.');
                return self::SUCCESS;
            }

            // Disable the extension
            $result = ExtensionsManager::disableExtension($extensionName, $force);

            if (!empty($result['error'])) {
                $this->error($result['error']);
                return self::FAILURE;
            }

            $this->success("Extension '{$extensionName}' disabled successfully!");
            $this->displayNextSteps($extensionName);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to disable extension '{$extensionName}': " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function validateExtensionCanBeDisabled(ExtensionsManager $manager, string $name): bool
    {
        $extension = $this->findExtension($manager, $name);

        if (!$extension) {
            $this->error("Extension '{$name}' not found.");
            return false;
        }

        if (!($extension['metadata']['enabled'] ?? false)) {
            $this->warning("Extension '{$name}' is already disabled.");
            return false;
        }

        return true;
    }

    private function validateDependents(ExtensionsManager $manager, string $name, bool $force): bool
    {
        $this->info('Checking for dependent extensions...');

        $extensions = $manager->getLoadedExtensions();
        $dependents = [];

        foreach ($extensions as $extension) {
            $metadata = $extension['metadata'];
            if (!($metadata['enabled'] ?? false)) {
                continue; // Skip disabled extensions
            }

            $dependencies = $metadata['dependencies']['extensions'] ?? [];
            if (in_array($name, $dependencies)) {
                $dependents[] = $extension['name'];
            }
        }

        if (empty($dependents)) {
            $this->line('✓ No dependent extensions found');
            return true;
        }

        $this->warning('The following extensions depend on this extension:');
        foreach ($dependents as $dependent) {
            $this->line("• {$dependent}");
        }

        if ($force) {
            $this->warning('Force flag specified - proceeding anyway');
            return true;
        }

        $this->line('');
        $this->line('Disabling this extension may break dependent extensions.');

        $choice = $this->choice('How would you like to proceed?', [
            'cancel' => 'Cancel the operation',
            'disable-dependents' => 'Disable dependent extensions first',
            'force' => 'Force disable (may break functionality)'
        ], 'cancel');

        switch ($choice) {
            case 'cancel':
                $this->info('Operation cancelled to prevent breaking dependent extensions.');
                return false;

            case 'disable-dependents':
                foreach ($dependents as $dependent) {
                    $this->line("Disabling dependent extension: {$dependent}");
                    $manager->disableExtension($dependent);
                }
                $this->success('Dependent extensions disabled successfully');
                return true;

            case 'force':
                $this->warning('Proceeding with force disable - dependent extensions may break');
                return true;

            default:
                return false;
        }
    }

    private function confirmDisable(string $name): bool
    {
        $this->warning("This will disable extension '{$name}' and its functionality.");
        return $this->confirm('Are you sure you want to continue?', false);
    }


    private function displayNextSteps(string $name): void
    {
        $this->line('');
        $this->info('Next steps:');
        $this->line("1. Extension '{$name}' is now inactive");
        $this->line('2. Associated functionality has been disabled');
        $this->line('3. Configuration settings are preserved');
        $this->line("4. Re-enable with: extensions:enable {$name}");
        $this->line('5. Restart application if required');
    }
}
