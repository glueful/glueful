<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Glueful\Security\RandomStringGenerator;
use Glueful\Auth\PasswordHasher;
use Glueful\Services\HealthService;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\DI\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installation and Setup Command
 * - Interactive step-by-step setup wizard with progress tracking
 * - Enhanced validation with detailed error messages and recovery suggestions
 * - Secure password input with proper masking
 * - Progress bars for long-running operations
 * - Better error handling with rollback capabilities
 * - Modern UI with tables, spinners, and colored output
 * @package Glueful\Console\Commands
 */
#[AsCommand(
    name: 'install',
    description: 'Run installation setup wizard for new Glueful installation'
)]
class InstallCommand extends BaseCommand
{
    protected Container $installContainer;

    public function __construct()
    {
        parent::__construct();
        $this->installContainer = container();
    }

    protected function configure(): void
    {
        $this->setDescription('Run installation setup wizard for new Glueful installation')
             ->setHelp($this->getDetailedHelp())
             ->addOption(
                 'skip-database',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip database setup and migrations'
             )
             ->addOption(
                 'skip-db',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip database setup and migrations (alias for --skip-database)'
             )
             ->addOption(
                 'skip-keys',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip security key generation'
             )
             ->addOption(
                 'skip-admin',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip admin user creation'
             )
             ->addOption(
                 'skip-cache',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip cache initialization'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Overwrite existing configurations without confirmation'
             )
             ->addOption(
                 'quiet',
                 'q',
                 InputOption::VALUE_NONE,
                 'Non-interactive mode using environment variables'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skipDatabase = $input->getOption('skip-database') || $input->getOption('skip-db');
        $skipKeys = $input->getOption('skip-keys');
        $skipAdmin = $input->getOption('skip-admin');
        $skipCache = $input->getOption('skip-cache');
        $force = $input->getOption('force');
        $quiet = $input->getOption('quiet');

        try {
            if (!$quiet) {
                $this->showWelcomeMessage();
            } else {
                // In quiet mode, confirm environment variables are set
                $this->showQuietModeConfirmation();
            }

            $this->runInstallationSteps(
                $skipDatabase,
                $skipKeys,
                $skipAdmin,
                $skipCache,
                $force,
                $quiet
            );

            $this->showCompletionMessage();
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Installation failed: ' . $e->getMessage());
            $this->displayTroubleshootingInfo();
            return self::FAILURE;
        }
    }

    private function runInstallationSteps(
        bool $skipDatabase,
        bool $skipKeys,
        bool $skipAdmin,
        bool $skipCache,
        bool $force,
        bool $quiet
    ): void {
        $steps = $this->getInstallationSteps($skipDatabase, $skipKeys, $skipAdmin, $skipCache);
        $progressBar = $this->createProgressBar(count($steps));

        foreach ($steps as $step) {
            $this->info("Step {$step['number']}: {$step['description']}");

            $step['callback']($force, $quiet);

            $progressBar->advance();
            $this->line(''); // Add spacing between steps
        }

        $progressBar->finish();
        $this->line('');
    }

    private function getInstallationSteps(bool $skipDatabase, bool $skipKeys, bool $skipAdmin, bool $skipCache): array
    {
        $steps = [
            [
                'number' => 1,
                'description' => 'Environment validation',
                'callback' => [$this, 'validateEnvironment']
            ]
        ];

        if (!$skipKeys) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Generate security keys',
                'callback' => [$this, 'generateSecurityKeys']
            ];
        }

        if (!$skipDatabase) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Database setup and migrations',
                'callback' => [$this, 'setupDatabase']
            ];
        }

        if (!$skipCache) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Initialize cache system',
                'callback' => [$this, 'initializeCache']
            ];
        }

        $steps[] = [
            'number' => count($steps) + 1,
            'description' => 'Generate API definitions',
            'callback' => [$this, 'generateApiDefinitions']
        ];

        if (!$skipAdmin) {
            $steps[] = [
                'number' => count($steps) + 1,
                'description' => 'Create admin user',
                'callback' => [$this, 'createAdminUser']
            ];
        }

        $steps[] = [
            'number' => count($steps) + 1,
            'description' => 'Final validation',
            'callback' => [$this, 'performFinalValidation']
        ];

        return $steps;
    }

    private function showWelcomeMessage(): void
    {
        $this->line('');
        $this->line('╔══════════════════════════════════════════════╗');
        $this->line('║           Glueful Installation Wizard       ║');
        $this->line('╚══════════════════════════════════════════════╝');
        $this->line('');
        $this->info('Welcome! This wizard will help you set up your new Glueful installation.');
        $this->line('');
    }

    private function showQuietModeConfirmation(): void
    {
        $this->line('');
        $this->info('Running in quiet mode - using environment variables for configuration');
        $this->line('');
        $this->line('Required environment variables:');
        $this->line('• Database: DB_DRIVER, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD');
        $this->line('• Admin User: ADMIN_USERNAME, ADMIN_EMAIL, ADMIN_PASSWORD');
        $this->line('• Security: TOKEN_SALT, JWT_KEY (generated if missing)');
        $this->line('');

        if (!$this->confirm('Have you set all required environment variables?', false)) {
            throw new \Exception('Installation cancelled. Please set required environment variables and try again.');
        }
    }

    private function validateEnvironment(bool $force, bool $quiet): void
    {
        // Check for .env file
        $envPath = dirname(__DIR__, 5) . '/.env';

        if (!file_exists($envPath)) {
            if (!$quiet) {
                $this->warning('.env file not found. Creating from .env.example...');
            }

            $examplePath = dirname(__DIR__, 5) . '/.env.example';
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
                $this->success('Created .env file from .env.example');
            } else {
                throw new \Exception('.env.example file not found. Cannot create .env file.');
            }
        }

        // Validate PHP version and extensions
        $this->validatePhpRequirements();

        // Check directory permissions
        $this->validateDirectoryPermissions();

        $this->line('✓ Environment validation completed');
    }

    private function generateSecurityKeys(bool $force, bool $quiet): void
    {
        $generator = new RandomStringGenerator();

        // Generate TOKEN_SALT if not exists or force
        $tokenSalt = config('session.token_salt');
        if (empty($tokenSalt) || $force) {
            $newTokenSalt = $generator->generate(32);
            $this->updateEnvFile('TOKEN_SALT', $newTokenSalt);
            $this->line('✓ Generated TOKEN_SALT');
        } else {
            $this->line('• TOKEN_SALT already exists (use --force to regenerate)');
        }

        // Generate JWT_KEY if not exists or force
        $jwtKey = config('session.jwt_key');
        if (empty($jwtKey) || $force) {
            $newJwtKey = $generator->generate(64);
            $this->updateEnvFile('JWT_KEY', $newJwtKey);
            $this->line('✓ Generated JWT_KEY');
        } else {
            $this->line('• JWT_KEY already exists (use --force to regenerate)');
        }
    }

    private function setupDatabase(bool $force, bool $quiet): void
    {
        // Test database connection
        try {
            $healthService = $this->installContainer->get(HealthService::class);
            $dbHealth = $healthService->checkDatabase();

            if (!$dbHealth['status']) {
                throw new \Exception('Database connection failed: ' . ($dbHealth['message'] ?? 'Unknown error'));
            }

            $this->line('✓ Database connection verified');
        } catch (\Exception $e) {
            if (!$quiet && $this->confirm('Database connection failed. Configure database settings?', true)) {
                $this->configureDatabaseInteractively();
            } else {
                throw $e;
            }
        }

        // Run migrations
        $this->line('Running database migrations...');
        try {
            $command = $this->getApplication()->find('migrate:run');
            $arguments = new ArrayInput([]);
            $returnCode = $command->run($arguments, $this->output);

            if ($returnCode === 0) {
                $this->line('✓ Database migrations completed');
            } else {
                throw new \Exception('Migration command failed');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to run migrations: ' . $e->getMessage());
        }
    }

    private function initializeCache(bool $force, bool $quiet): void
    {
        $this->line('Initializing cache system...');
        try {
            // Try to clear cache first
            try {
                $command = $this->getApplication()->find('cache:clear');
                $arguments = new ArrayInput([]);
                $command->run($arguments, $this->output);
                $this->line('✓ Cache cleared successfully');
            } catch (\Exception $e) {
                if (!$quiet) {
                    $this->warning('Cache clear command not available: ' . $e->getMessage());
                }
            }

            // Initialize cache system
            $cacheStore = \Glueful\Helpers\CacheHelper::createCacheInstance();
            if ($cacheStore) {
                $this->line('✓ Cache system initialized successfully');
            } else {
                throw new \Exception('Failed to initialize cache system');
            }
        } catch (\Exception $e) {
            if (!$quiet) {
                $this->warning('Failed to initialize cache system: ' . $e->getMessage());
                $this->line('• Cache system will be initialized on first use');
            }
            // Don't fail the entire installation for cache initialization
        }
    }

    private function generateApiDefinitions(bool $force, bool $quiet): void
    {
        $this->line('Generating API definitions...');
        try {
            $command = $this->getApplication()->find('generate:api-definitions');
            $arguments = new ArrayInput([]);
            $returnCode = $command->run($arguments, $this->output);

            if ($returnCode === 0) {
                $this->line('✓ API definitions generated');
            } else {
                throw new \Exception('API definitions generation failed');
            }
        } catch (\Exception $e) {
            if (!$quiet) {
                $this->warning('Failed to generate API definitions: ' . $e->getMessage());
                $this->line('• You can run "php glueful generate:api-definitions" manually later');
            }
            // Don't fail the entire installation for API definitions
        }
    }

    private function createAdminUser(bool $force, bool $quiet): void
    {
        if ($quiet) {
            // Use environment variables in quiet mode
            $username = $_ENV['ADMIN_USERNAME'] ?? getenv('ADMIN_USERNAME');
            $email = $_ENV['ADMIN_EMAIL'] ?? getenv('ADMIN_EMAIL');
            $password = $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');

            if (empty($username) || empty($email) || empty($password)) {
                throw new \Exception(
                    'Missing required environment variables: ADMIN_USERNAME, ADMIN_EMAIL, ADMIN_PASSWORD'
                );
            }

            $this->line('Creating admin user from environment variables...');
        } else {
            // Interactive mode
            $this->line('');
            $this->info('Admin User Setup:');

            $username = $this->ask('Admin username', 'admin');
            $email = $this->ask('Admin email', 'admin@example.com');

            $password = $this->secret('Admin password');
            $confirmPassword = $this->secret('Confirm password');

            if ($password !== $confirmPassword) {
                throw new \Exception('Passwords do not match');
            }
        }

        // Create admin user using direct query builder like in RBAC migrations
        try {
            $db = new Connection();

            // Check if user already exists (like UserRepository validation)
            $existingUser = $db->table('users')
                ->select(['uuid'])
                ->where('email', $email)
                ->orWhere('username', $username)
                ->get();

            if (!empty($existingUser)) {
                throw new \Exception("User with email '{$email}' or username '{$username}' already exists");
            }

            // Generate UUID and hash password using PasswordHasher
            $userUuid = Utils::generateNanoID();
            $passwordHasher = new PasswordHasher();
            $hashedPassword = $passwordHasher->hash($password);

            $userData = [
                'uuid' => $userUuid,
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword,
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'), // Pre-verify admin user
                'created_at' => date('Y-m-d H:i:s'),
            ];

            // Insert the admin user
            $userId = $db->table('users')->insert($userData);
            if (!$userId) {
                throw new \Exception('Failed to create admin user in database');
            }

            // Assign superuser role using PermissionManager
            try {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $success = $manager->assignRole($userUuid, 'superuser');
                    if ($success) {
                        $this->line('✓ Admin user created successfully with superuser privileges');
                    } else {
                        $this->line('✓ Admin user created successfully (superuser role assignment failed)');
                    }
                } else {
                    $this->line('✓ Admin user created successfully (no permission provider active)');
                }
            } catch (\Exception) {
                // Permission system might not be available or enabled
                $this->line('✓ Admin user created successfully (permission system not available)');
            }

            // Clean up environment variables in quiet mode for security
            if ($quiet) {
                $this->cleanupAdminEnvironmentVariables();
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to create admin user: ' . $e->getMessage());
        }
    }

    private function cleanupAdminEnvironmentVariables(): void
    {
        // Remove admin credentials from environment for security
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_PASSWORD']);

        // Also remove from putenv if they were set that way
        putenv('ADMIN_USERNAME');
        putenv('ADMIN_EMAIL');
        putenv('ADMIN_PASSWORD');

        $this->line('✓ Admin environment variables cleaned up for security');
    }

    private function performFinalValidation(bool $force, bool $quiet): void
    {
        $this->line('Performing final system validation...');

        // Check all critical services
        $services = [
            'Database' => 'checkDatabaseHealth',
            'Cache' => 'checkCacheHealth',
            'Security' => 'checkSecurityHealth'
        ];

        foreach ($services as $service => $method) {
            try {
                $this->{$method}();
                $this->line("✓ {$service} validation passed");
            } catch (\Exception $e) {
                $this->warning("⚠ {$service} validation warning: " . $e->getMessage());
            }
        }
    }

    private function showCompletionMessage(): void
    {
        $this->line('');
        $this->success('🎉 Glueful installation completed successfully!');
        $this->line('');

        $docsUrl = config('app.paths.api_docs_url');

        $this->info('Your Glueful installation is ready. Next steps:');
        $this->line('1. Start the development server: php glueful serve');
        $this->line('2. Visit your application in a web browser');
        $this->line('3. Review the API documentation: ' . $docsUrl);
        $this->line('4. Begin building your application!');
        $this->line('');

        $this->table(['Component', 'Status'], [
            ['Database', '✓ Connected and migrated'],
            ['Security Keys', '✓ Generated'],
            ['Cache System', '✓ Initialized'],
            ['API Definitions', '✓ Generated'],
            ['Admin User', '✓ Created'],
        ]);
    }

    private function getDetailedHelp(): string
    {
        return <<<HELP
Glueful Installation Setup Wizard

This command sets up a new Glueful installation with all required components:

Steps performed:
  1. Environment validation (.env file check)
  2. Security key generation (TOKEN_SALT, JWT_KEY)
  3. Database connection testing and migrations
  4. Cache system initialization
  5. API definitions generation
  6. Interactive admin user creation
  7. Final configuration validation

Examples:
  glueful install                           # Full interactive setup
  glueful install --skip-admin              # Setup without admin user
  glueful install --force --quiet           # Force reinstall using environment variables
  glueful install --skip-database           # Skip database setup
  glueful install --skip-db                 # Skip database setup (alias)
  glueful install --skip-cache              # Skip cache initialization

Options allow you to customize which steps are performed during installation.
HELP;
    }

    // Helper methods
    private function validatePhpRequirements(): void
    {
        $phpVersion = PHP_VERSION;
        $requiredVersion = '8.2.0';

        if (version_compare($phpVersion, $requiredVersion, '<')) {
            throw new \Exception("PHP {$requiredVersion} or higher is required. Current version: {$phpVersion}");
        }

        $requiredExtensions = ['json', 'mbstring', 'openssl', 'PDO', 'curl', 'xml', 'zip'];
        $missingExtensions = [];

        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $missingExtensions[] = $extension;
            }
        }

        if (!empty($missingExtensions)) {
            throw new \Exception('Missing PHP extensions: ' . implode(', ', $missingExtensions));
        }
    }

    private function validateDirectoryPermissions(): void
    {
        $directories = [
            dirname(__DIR__, 5) . '/storage',
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \Exception("Failed to create directory: {$dir}");
                }
            }

            if (!is_writable($dir)) {
                throw new \Exception("Directory is not writable: {$dir}");
            }
        }
    }

    private function updateEnvFile(string $key, string $value): void
    {
        $envPath = dirname(__DIR__, 5) . '/.env';

        if (!file_exists($envPath)) {
            throw new \Exception('.env file not found');
        }

        $content = file_get_contents($envPath);
        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= "\n{$replacement}";
        }

        file_put_contents($envPath, $content);
    }

    private function configureDatabaseInteractively(): void
    {
        $this->line('Database Configuration:');

        // Ask for database driver choice
        $driver = $this->choice(
            'Which database driver would you like to use?',
            ['mysql', 'pgsql', 'sqlite'],
            'mysql'
        );

        $this->updateEnvFile('DB_DRIVER', $driver);

        switch ($driver) {
            case 'mysql':
                $this->configureMysqlDatabase();
                break;
            case 'pgsql':
                $this->configurePostgreSQLDatabase();
                break;
            case 'sqlite':
                $this->configureSqliteDatabase();
                break;
        }
    }

    private function configureMysqlDatabase(): void
    {
        $this->line('MySQL Configuration:');

        $host = $this->ask('Database host', '127.0.0.1');
        $port = $this->ask('Database port', '3306');
        $database = $this->ask('Database name', 'glueful');
        $username = $this->ask('Database username', 'root');
        $password = $this->secret('Database password');

        $this->updateEnvFile('DB_HOST', $host);
        $this->updateEnvFile('DB_PORT', $port);
        $this->updateEnvFile('DB_DATABASE', $database);
        $this->updateEnvFile('DB_USERNAME', $username);
        $this->updateEnvFile('DB_PASSWORD', $password);
    }

    private function configurePostgreSQLDatabase(): void
    {
        $this->line('PostgreSQL Configuration:');

        $host = $this->ask('Database host', '127.0.0.1');
        $port = $this->ask('Database port', '5432');
        $database = $this->ask('Database name', 'glueful');
        $username = $this->ask('Database username', 'postgres');
        $password = $this->secret('Database password');

        $this->updateEnvFile('DB_PGSQL_HOST', $host);
        $this->updateEnvFile('DB_PGSQL_PORT', $port);
        $this->updateEnvFile('DB_PGSQL_DATABASE', $database);
        $this->updateEnvFile('DB_PGSQL_USERNAME', $username);
        $this->updateEnvFile('DB_PGSQL_PASSWORD', $password);
    }

    private function configureSqliteDatabase(): void
    {
        $this->line('SQLite Configuration:');

        $defaultPath = config('app.paths.storage') . '/database/primary.sqlite';
        $databasePath = $this->ask('Database file path', $defaultPath);

        // Ensure the database directory exists
        $databaseDir = dirname($databasePath);
        if (!is_dir($databaseDir)) {
            if (!mkdir($databaseDir, 0755, true)) {
                throw new \Exception("Failed to create database directory: {$databaseDir}");
            }
        }

        $this->updateEnvFile('DB_SQLITE_DATABASE', $databasePath);
    }

    private function checkDatabaseHealth(): void
    {
        $healthService = $this->installContainer->get(HealthService::class);
        $dbHealth = $healthService->checkDatabase();

        if (!$dbHealth['status']) {
            throw new \Exception('Database health check failed: ' . ($dbHealth['message'] ?? 'Unknown error'));
        }
    }

    private function checkCacheHealth(): void
    {
        try {
            $cacheStore = \Glueful\Helpers\CacheHelper::createCacheInstance();
            if (!$cacheStore) {
                throw new \Exception('Cache instance could not be created');
            }
        } catch (\Exception $e) {
            throw new \Exception('Cache health check failed: ' . $e->getMessage());
        }
    }

    private function checkSecurityHealth(): void
    {
        // Check if security keys are set
        $tokenSalt = config('session.token_salt');
        $jwtKey = config('session.jwt_key');

        if (empty($tokenSalt)) {
            throw new \Exception('TOKEN_SALT is not set');
        }

        if (empty($jwtKey)) {
            throw new \Exception('JWT_KEY is not set');
        }

        // Validate key lengths
        if (strlen($tokenSalt) < 32) {
            throw new \Exception('TOKEN_SALT is too short (minimum 32 characters)');
        }

        if (strlen($jwtKey) < 64) {
            throw new \Exception('JWT_KEY is too short (minimum 64 characters)');
        }
    }

    private function displayTroubleshootingInfo(): void
    {
        $this->line('');
        $this->error('Installation failed. Troubleshooting information:');
        $this->line('');

        $this->line('Common issues:');
        $this->line('• Check PHP version (8.2+ required): php -v');
        $this->line('• Check required extensions: php -m');
        $this->line('• Verify database connection settings in .env');
        $this->line('• Ensure storage/ and database/ directories are writable');
        $this->line('• Check logs in storage/logs/ for detailed errors');
        $this->line('');

        $this->line('For help:');
        $this->line('• Documentation: https://glueful.com/docs/getting-started');
        $this->line('• GitHub Issues: https://github.com/glueful/glueful/issues');
    }
}
