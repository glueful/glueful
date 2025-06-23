<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Security\RandomStringGenerator;
use Glueful\Helpers\Utils;
use Glueful\Services\HealthService;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Exceptions\BusinessLogicException;

/**
 * Installation and Setup Command
 *
 * Comprehensive setup wizard for new Glueful installations:
 * - Environment validation
 * - Database connection testing
 * - Key generation
 * - Database migrations
 * - API definitions generation
 * - Initial admin user creation
 * - Configuration validation
 */
class InstallCommand extends Command
{
    use DatabaseConnectionTrait;

    /**
     * Service container for dependency injection
     */
    private ContainerInterface $container;

    /**
     * Constructor - uses dependency injection
     *
     * @param ContainerInterface|null $container Optional DI container
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? container();
    }

    /**
     * Prompt user for input with default value
     */
    private function prompt(string $question, string $default = ''): string
    {
        if (!empty($default)) {
            echo "$question [$default]: ";
        } else {
            echo "$question: ";
        }

        $input = trim(fgets(STDIN));
        return empty($input) ? $default : $input;
    }

    /**
     * Prompt user for password (hidden input)
     */
    private function promptPassword(string $question): string
    {
        echo "$question: ";

        // Disable echo for password input
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $password = '';
            while (true) {
                $char = fgetc(STDIN);
                if ($char === "\r" || $char === "\n") {
                    break;
                }
                if ($char === "\x08") { // Backspace
                    if (strlen($password) > 0) {
                        $password = substr($password, 0, -1);
                        echo "\x08 \x08";
                    }
                } else {
                    $password .= $char;
                    echo '*';
                }
            }
        } else {
            // Unix/Linux/Mac
            system('stty -echo');
            $password = trim(fgets(STDIN));
            system('stty echo');
        }

        echo "\n";
        return $password;
    }

    public function getName(): string
    {
        return 'install';
    }

    public function getDescription(): string
    {
        return 'Run installation setup wizard for new Glueful installation';
    }

    public function getHelp(): string
    {
        return <<<HELP
Glueful Installation Setup Wizard

Usage:
    php glueful install [options]

Options:
    --skip-db        Skip database setup
    --skip-keys      Skip key generation
    --skip-admin     Skip admin user creation
    --force          Overwrite existing configurations
    --quiet          Run without interactive prompts

Examples:
    php glueful install                    # Full interactive setup
    php glueful install --skip-admin       # Setup without admin user
    php glueful install --force --quiet    # Force reinstall silently

Steps performed:
    1. Environment validation (.env file check)
    2. Key generation (APP_KEY, JWT_KEY)
    3. Database connection testing
    4. Database migrations
    5. API definitions generation
    6. Interactive admin user creation
    7. Final configuration validation
HELP;
    }

    public function execute(array $args = []): int
    {
        if (isset($args[0]) && in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $skipDb = in_array('--skip-db', $args);
        $skipKeys = in_array('--skip-keys', $args);
        $skipAdmin = in_array('--skip-admin', $args);
        $force = in_array('--force', $args);
        $quiet = in_array('--quiet', $args);

        if (!$quiet) {
            $this->showWelcome();
        }

        // Step 1: Environment validation
        if (!$this->validateEnvironment($quiet)) {
            return self::FAILURE;
        }

        // Step 1.5: Optional environment configuration
        if (!$quiet && !$this->configureEnvironment()) {
            return self::FAILURE;
        }

        // Step 2: Key generation
        if (!$skipKeys && !$this->generateKeys($force, $quiet)) {
            return self::FAILURE;
        }

        // Step 3: Database setup
        if (!$skipDb && !$this->setupDatabase($quiet)) {
            return self::FAILURE;
        }

        // Step 4: Database migrations
        if (!$skipDb && !$this->runMigrations($quiet)) {
            return self::FAILURE;
        }

        // Step 5: Generate API definitions
        if (!$this->generateApiDefinitions($quiet)) {
            return self::FAILURE;
        }

        // Step 6: Create admin user
        if (!$skipAdmin && !$this->createAdminUser($force, $quiet)) {
            return self::FAILURE;
        }

        // Step 7: Final validation
        if (!$this->finalValidation($quiet)) {
            return self::FAILURE;
        }

        if (!$quiet) {
            $this->showCompletionMessage();
        }

        return self::SUCCESS;
    }

    private function showWelcome(): void
    {
        $this->line('');
        $this->info('🚀 Glueful Installation Setup Wizard');
        $this->line('=====================================');
        $this->line('This wizard will help you set up your Glueful API framework.');
        $this->line('');
    }

    private function validateEnvironment(bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('📋 Step 1: Validating environment...');
        }

        $envPath = dirname(__DIR__, 3) . '/.env';

        if (!file_exists($envPath)) {
            $this->error('.env file not found.');
            $this->line('Please copy .env.example to .env first:');
            $this->line('  cp .env.example .env');
            return false;
        }

        // Check required PHP extensions
        $requiredExtensions = ['pdo', 'json', 'mbstring', 'openssl'];
        $missing = [];

        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if (!empty($missing)) {
            $this->error('Missing required PHP extensions: ' . implode(', ', $missing));
            return false;
        }

        if (!$quiet) {
            $this->success('✓ Environment validation passed');
        }
        return true;
    }

    private function generateKeys(bool $force, bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('🔐 Step 2: Generating security keys...');
        }

        // Use existing KeyGenerateCommand via container
        $keyCommand = $this->container->get(KeyGenerateCommand::class);
        $args = $force ? ['--force'] : [];

        $result = $keyCommand->execute($args);

        if ($result === self::SUCCESS) {
            if (!$quiet) {
                $this->success('✓ Security keys generated');
            }
            return true;
        }

        return false;
    }

    private function setupDatabase(bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('🗄️  Step 3: Testing database connection...');
        }

        try {
            // Test connection by initializing QueryBuilder (which creates the connection)
            $this->getQueryBuilder();

            if (!$quiet) {
                $this->success('✓ Database connection successful');
            }
            return true;
        } catch (\Exception $e) {
            $this->error('Database connection failed: ' . $e->getMessage());
            $this->line('Please check your database configuration in .env file');
            return false;
        }
    }

    private function runMigrations(bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('📊 Step 4: Running database migrations...');
        }

        // Use existing MigrateCommand via container
        $migrateCommand = $this->container->get(MigrateCommand::class);
        $result = $migrateCommand->execute([]);

        if ($result === self::SUCCESS) {
            if (!$quiet) {
                $this->success('✓ Database migrations completed');
            }
            return true;
        }

        return false;
    }

    private function generateApiDefinitions(bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('📋 Step 5: Generating API definitions...');
        }

        // Use existing GenerateJsonCommand via container
        $generateCommand = $this->container->get(GenerateJsonCommand::class);
        $result = $generateCommand->execute(['api-definitions']);

        if ($result === self::SUCCESS) {
            if (!$quiet) {
                $this->success('✓ API definitions generated');
            }
            return true;
        }

        return false;
    }

    private function createAdminUser(bool $force, bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('👤 Step 6: Creating admin user...');
        }

        try {
            $queryBuilder = $this->getQueryBuilder();

            // Get admin details interactively or use defaults for quiet mode
            if ($quiet) {
                // Quiet mode defaults
                $username = 'admin';
                $email = 'admin@yourdomain.com';
                $password = RandomStringGenerator::generate(16);
                $firstName = 'Admin';
                $lastName = 'User';
            } else {
                // Interactive mode
                $this->line('');
                $this->line('Let\'s create your admin user account:');
                $this->line('');

                $username = $this->prompt('Admin username', 'admin');
                $email = $this->prompt('Admin email', 'admin@yourdomain.com');

                $this->line('');
                $this->line('Enter admin password (leave empty to generate a random one):');
                $password = $this->promptPassword('Password');

                if (empty($password)) {
                    $password = RandomStringGenerator::generate(16);
                    $this->line('Generated random password: ' . $password);
                }

                $firstName = $this->prompt('First name', 'Admin');
                $lastName = $this->prompt('Last name', 'User');
                $this->line('');
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Check if admin user already exists
            $existingUser = $queryBuilder->select('users', ['uuid', 'username', 'email'])
                ->where(['email' => $email])
                ->get();

            if (!empty($existingUser) && !$force) {
                if (!$quiet) {
                    $this->warning('Admin user with email "' . $email . '" already exists.');
                    $this->line('Use --force to overwrite or choose a different email.');
                }
                return false;
            }

            // Note: Role assignment moved to RBAC extension
            // For now, create admin user without role assignment
            // Use RBAC extension to assign admin permissions after installation

            // Clean up existing user if force mode
            if ($force && !empty($existingUser)) {
                $existingUuid = $existingUser[0]['uuid'];

                // Delete in dependency order (removed user_roles_lookup - now handled by RBAC)
                $queryBuilder->delete('profiles', ['user_uuid' => $existingUuid], false);
                $queryBuilder->delete('users', ['uuid' => $existingUuid], false);

                if (!$quiet) {
                    $this->line('✓ Cleaned up existing admin user');
                }
            }

            // Create admin user
            $adminUuid = Utils::generateNanoID();
            $userId = $queryBuilder->insert('users', [
                'uuid' => $adminUuid,
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            if (!$userId) {
                throw BusinessLogicException::operationNotAllowed(
                    'admin_user_creation',
                    'Failed to create admin user'
                );
            }

            // Create profile for admin user
            $profileUuid = Utils::generateNanoID();
            $profileId = $queryBuilder->insert('profiles', [
                'uuid' => $profileUuid,
                'user_uuid' => $adminUuid,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'status' => 'active'
            ]);

            if (!$profileId) {
                throw BusinessLogicException::operationNotAllowed(
                    'admin_profile_creation',
                    'Failed to create admin profile'
                );
            }

            // Note: Role assignment moved to RBAC extension
            // Admin permissions should be assigned using RBAC extension after installation

            if (!$quiet) {
                $this->success('✓ Admin user created successfully!');
                $this->line('');
                $this->line('Admin credentials:');
                $this->line('  Username: ' . $username);
                $this->line('  Email: ' . $email);
                $this->line('  Password: ' . $password);
                $this->line('');
                $this->warning('Please save these credentials securely and change the password after first login!');
                $this->line('');
                $this->info('📋 Note: Admin permissions are managed by the RBAC extension.');
                $this->line('   After installation, use RBAC APIs to assign admin permissions.');
            }

            return true;
        } catch (\Exception $e) {
            if (!$quiet) {
                $this->error('Failed to create admin user: ' . $e->getMessage());
            }
            return false;
        }
    }

    private function finalValidation(bool $quiet): bool
    {
        if (!$quiet) {
            $this->info('✅ Step 7: Final validation...');
        }

        // Use HealthService for comprehensive system validation
        $healthCheck = HealthService::getOverallHealth();

        // Show production warnings if applicable (non-blocking)
        if (!$quiet && env('APP_ENV') === 'production') {
            if (!\Glueful\Security\SecurityManager::shouldSuppressProductionWarnings()) {
                \Glueful\Security\SecurityManager::displayProductionWarnings();
            }
        }

        if ($healthCheck['status'] === 'ok') {
            if (!$quiet) {
                $this->success('✓ All systems ready');
            }
            return true;
        }

        if (!$quiet) {
            $this->error('System validation failed:');
            foreach ($healthCheck['checks'] as $checkName => $check) {
                if ($check['status'] !== 'ok') {
                    $this->line("  ❌ $checkName: " . $check['message']);
                }
            }
        }

        return false;
    }

    private function showCompletionMessage(): void
    {
        $this->line('');
        $this->success('🎉 Glueful installation completed successfully!');
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Start development server: php glueful serve');
        $this->line('  2. Visit API docs: http://localhost:8000/docs/');
        $this->line('  3. Login with your admin credentials');
        $this->line('');
        $this->line('Useful commands:');
        $this->line('  php glueful help           # Show all commands');
        $this->line('  php glueful db:status      # Check database status');
        $this->line('  php glueful cache:clear    # Clear application cache');
        $this->line('');
    }

    /**
     * Configure environment-specific settings (optional)
     */
    private function configureEnvironment(): bool
    {
        $currentEnv = env('APP_ENV', 'development');
        $this->line('');
        $this->info("Current environment: $currentEnv");

        $envChoice = $this->prompt(
            'Which environment are you setting up? (development/staging/production)',
            $currentEnv
        );

        if ($envChoice === 'production') {
            $this->line('');
            $this->warning('⚠️  Production Environment Setup');
            $this->line('Setting up for production requires additional security considerations.');
            $this->line('');

            $useTemplate = $this->prompt(
                'Would you like to use the production template (.env.production)? (y/n)',
                'y'
            );

            if (strtolower($useTemplate) === 'y') {
                return $this->applyProductionTemplate();
            } else {
                $this->line('Continuing with current .env configuration...');
                $this->line('💡 Remember to review production security settings manually.');
            }
        } elseif ($envChoice === 'staging') {
            $this->line('');
            $this->info('💡 Staging Environment');
            $this->line('Staging uses production-like security with relaxed rate limits.');
            $this->line('Consider using the production template as a starting point.');
        }

        // Update APP_ENV if different
        if ($envChoice !== $currentEnv) {
            $this->updateEnvValue('APP_ENV', $envChoice);
            $this->success("✓ Environment updated to: $envChoice");
        }

        return true;
    }

    /**
     * Apply production environment template
     */
    private function applyProductionTemplate(): bool
    {
        $productionTemplate = dirname(__DIR__, 3) . '/.env.production';
        $envFile = dirname(__DIR__, 3) . '/.env';

        if (!file_exists($productionTemplate)) {
            $this->error('Production template (.env.production) not found');
            return false;
        }

        $this->line('');
        $this->warning('This will update your .env file with production-ready defaults.');
        $backup = $this->prompt('Create backup of current .env? (y/n)', 'y');

        if (strtolower($backup) === 'y') {
            $backupFile = $envFile . '.backup.' . date('Y-m-d-H-i-s');
            if (file_exists($envFile)) {
                copy($envFile, $backupFile);
                $this->success("✓ Backup created: " . basename($backupFile));
            }
        }

        // Read production template
        $templateContent = file_get_contents($productionTemplate);

        // Preserve existing custom values if .env exists
        if (file_exists($envFile)) {
            $existingContent = file_get_contents($envFile);
            $templateContent = $this->mergeEnvConfigs($existingContent, $templateContent);
        }

        file_put_contents($envFile, $templateContent);

        $this->line('');
        $this->success('✓ Production template applied successfully');
        $this->line('');
        $this->warning('IMPORTANT: Review and update the following before deployment:');
        $this->line('  • APP_KEY and JWT_KEY (use: php glueful key:generate)');
        $this->line('  • Database credentials (DB_HOST, DB_DATABASE, DB_USER, DB_PASSWORD)');
        $this->line('  • CORS_ALLOWED_ORIGINS (replace with your actual domains)');
        $this->line('  • Redis credentials if using cache');
        $this->line('  • Email configuration for notifications');
        $this->line('');

        return true;
    }

    /**
     * Merge existing environment values with template
     */
    private function mergeEnvConfigs(string $existing, string $template): string
    {
        // Parse existing values to preserve custom settings
        $existingValues = [];
        foreach (explode("\n", $existing) as $line) {
            if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                [$key, $value] = explode('=', $line, 2);
                $existingValues[trim($key)] = trim($value);
            }
        }

        // Apply existing values to template where they exist
        $templateLines = explode("\n", $template);
        $mergedLines = [];

        foreach ($templateLines as $line) {
            if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                [$key, $templateValue] = explode('=', $line, 2);
                $key = trim($key);

                // Keep existing value if it's not a default/placeholder
                if (
                    isset($existingValues[$key]) &&
                    !$this->isPlaceholderValue($existingValues[$key])
                ) {
                    $mergedLines[] = "$key={$existingValues[$key]}";
                } else {
                    $mergedLines[] = $line;
                }
            } else {
                $mergedLines[] = $line;
            }
        }

        return implode("\n", $mergedLines);
    }

    /**
     * Check if a value is a placeholder that should be replaced
     */
    private function isPlaceholderValue(string $value): bool
    {
        $placeholders = [
            'generate-secure-32-char-key-here',
            'your-secure-jwt-key-here',
            'your-secure-salt-here',
            'GENERATE_SECURE_32_CHARACTER_KEY_HERE',
            'GENERATE_SECURE_JWT_SECRET_KEY_HERE',
            'GENERATE_SECURE_SALT_HERE',
            'your-database-host',
            'your_production_database',
            'your_database_user',
            'SECURE_DATABASE_PASSWORD_HERE'
        ];

        return in_array(trim($value, '"\''), $placeholders);
    }

    /**
     * Update a single environment value
     */
    private function updateEnvValue(string $key, string $value): bool
    {
        $envFile = dirname(__DIR__, 3) . '/.env';

        if (!file_exists($envFile)) {
            return false;
        }

        $content = file_get_contents($envFile);
        $pattern = "/^{$key}=.*/m";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            $content .= "\n{$key}={$value}";
        }

        file_put_contents($envFile, $content);
        return true;
    }
}
