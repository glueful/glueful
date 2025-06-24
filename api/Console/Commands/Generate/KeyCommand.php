<?php

namespace Glueful\Console\Commands\Generate;

use Glueful\Console\BaseCommand;
use Glueful\Security\RandomStringGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate Key Command
 * - Interactive validation for existing keys with confirmation prompts
 * - Enhanced security warnings and best practices guidance
 * - Progress indicators for key generation
 * - Detailed validation with helpful error messages
 * - Better organization of key types and generation options
 * @package Glueful\Console\Commands\Generate
 */
#[AsCommand(
    name: 'generate:key',
    description: 'Generate secure encryption keys for the framework'
)]
class KeyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Generate secure encryption keys for the framework')
             ->setHelp($this->getDetailedHelp())
             ->addOption(
                 'jwt-only',
                 null,
                 InputOption::VALUE_NONE,
                 'Generate JWT secret key only'
             )
             ->addOption(
                 'app-only',
                 null,
                 InputOption::VALUE_NONE,
                 'Generate application encryption key only'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Overwrite existing keys without confirmation'
             )
             ->addOption(
                 'show',
                 null,
                 InputOption::VALUE_NONE,
                 'Display generated keys (insecure - not recommended for production)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jwtOnly = $input->getOption('jwt-only');
        $appOnly = $input->getOption('app-only');
        $force = $input->getOption('force');
        $show = $input->getOption('show');

        if ($jwtOnly && $appOnly) {
            $this->error('Cannot use both --jwt-only and --app-only options together.');
            return self::FAILURE;
        }

        try {
            $this->validateEnvironment();

            if ($show) {
                $this->displaySecurityWarning();
            }

            $generator = new RandomStringGenerator();
            $results = [];

            if (!$jwtOnly) {
                $results['APP_KEY'] = $this->generateAppKey($generator, $force);
            }

            if (!$appOnly) {
                $results['JWT_KEY'] = $this->generateJwtKey($generator, $force);
            }

            $this->displayResults($results, $show);
            $this->displaySecurityReminders();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Key generation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function validateEnvironment(): void
    {
        $envPath = dirname(__DIR__, 4) . '/.env';

        if (!file_exists($envPath)) {
            throw new \Exception('.env file not found. Copy .env.example first.');
        }

        if (!is_writable($envPath)) {
            throw new \Exception('.env file is not writable. Check file permissions.');
        }
    }

    private function generateAppKey(RandomStringGenerator $generator, bool $force): array
    {
        $currentKey = config('app.key');

        if (!empty($currentKey) && !$force) {
            if (!$this->confirm('APP_KEY already exists. Overwrite?', false)) {
                return [
                    'status' => 'skipped',
                    'message' => 'APP_KEY generation skipped by user',
                    'key' => null
                ];
            }
        }

        $this->info('Generating APP_KEY...');
        $newKey = $generator->generate(32);

        $this->updateEnvFile('APP_KEY', $newKey);

        return [
            'status' => 'generated',
            'message' => 'APP_KEY generated successfully',
            'key' => $newKey
        ];
    }

    private function generateJwtKey(RandomStringGenerator $generator, bool $force): array
    {
        $currentKey = config('jwt.key');

        if (!empty($currentKey) && !$force) {
            if (!$this->confirm('JWT_KEY already exists. Overwrite?', false)) {
                return [
                    'status' => 'skipped',
                    'message' => 'JWT_KEY generation skipped by user',
                    'key' => null
                ];
            }
        }

        $this->info('Generating JWT_KEY...');
        $newKey = $generator->generate(64);

        $this->updateEnvFile('JWT_KEY', $newKey);

        return [
            'status' => 'generated',
            'message' => 'JWT_KEY generated successfully',
            'key' => $newKey
        ];
    }

    private function updateEnvFile(string $key, string $value): void
    {
        $envPath = dirname(__DIR__, 4) . '/.env';
        $envContent = file_get_contents($envPath);

        $pattern = "/^{$key}=.*$/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $envContent)) {
            // Update existing key
            $envContent = preg_replace($pattern, $replacement, $envContent);
        } else {
            // Add new key
            $envContent .= "\n{$replacement}";
        }

        file_put_contents($envPath, $envContent);
    }

    private function displaySecurityWarning(): void
    {
        $this->warning('🔒 SECURITY WARNING');
        $this->line('The --show option will display your encryption keys in plain text.');
        $this->line('This is insecure and should never be used in production environments.');
        $this->line('Keys will be visible in your terminal history and logs.');
        $this->line('');

        if (!$this->confirm('Do you understand the security risks and want to continue?', false)) {
            throw new \Exception('Key generation cancelled for security reasons.');
        }
    }

    private function displayResults(array $results, bool $show): void
    {
        $this->line('');
        $this->success('🔑 Key Generation Results:');
        $this->line('');

        $tableData = [];
        foreach ($results as $keyType => $result) {
            $status = $result['status'] === 'generated' ? '✓ Generated' : '• Skipped';
            $value = ($show && $result['key']) ? $result['key'] : '••••••••••••••••';

            $tableData[] = [$keyType, $status, $value];
        }

        $this->table(['Key Type', 'Status', 'Value'], $tableData);
    }

    private function displaySecurityReminders(): void
    {
        $this->line('');
        $this->info('🛡️  Security Reminders:');
        $this->line('1. Never commit encryption keys to version control');
        $this->line('2. Use different keys for each environment (dev/staging/prod)');
        $this->line('3. Rotate keys periodically for enhanced security');
        $this->line('4. Backup keys securely before rotating');
        $this->line('5. Ensure .env file has proper permissions (600 or 644)');

        // Check .env permissions
        $envPath = dirname(__DIR__, 4) . '/.env';
        $permissions = substr(sprintf('%o', fileperms($envPath)), -3);

        if ($permissions !== '600' && $permissions !== '644') {
            $this->warning("⚠️  .env file permissions are {$permissions}. Consider setting to 600 or 644.");
            $this->tip("Run: chmod 600 {$envPath}");
        }
    }

    private function getDetailedHelp(): string
    {
        return <<<HELP
Generate secure encryption keys for your Glueful application.

This command generates cryptographically secure random keys for:
- APP_KEY: Used for general application encryption (32 characters)
- JWT_KEY: Used for JWT token signing and verification (64 characters)

Security Features:
- Uses cryptographically secure random number generation
- Automatically updates .env file with new keys
- Warns about security implications when showing keys
- Validates environment before generation

Examples:
  generate:key                    # Generate both APP_KEY and JWT_KEY
  generate:key --jwt-only         # Generate only JWT_KEY
  generate:key --app-only         # Generate only APP_KEY
  generate:key --force            # Overwrite existing keys
  generate:key --show             # Show generated keys (not recommended)

Note: Existing keys will not be overwritten unless --force is used
or you confirm the overwrite when prompted.
HELP;
    }
}
