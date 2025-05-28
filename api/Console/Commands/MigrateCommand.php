<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\DI\Interfaces\ContainerInterface;

/**
 * Comprehensive Migration Management System
 *
 * Handles all migration operations:
 * - create: Generate new migration files
 * - run: Execute pending migrations
 * - rollback: Revert migrations
 * - status: Show migration status
 * - reset: Rollback all migrations
 * - refresh: Reset and re-run migrations
 * - install: Create migrations table
 *
 *
 * @package Glueful\Console\Commands
 */
class MigrateCommand extends Command
{
    /** @var MigrationManager Database migration handler */
    private MigrationManager $migrationManager;

    /** @var ContainerInterface DI Container */
    protected ContainerInterface $container;

    /**
     * Initialize Migration Command
     *
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? app();
        $this->migrationManager = $this->container->get(MigrationManager::class);
    }

    /**
     * Get Command Name
     */
    public function getName(): string
    {
        return 'migrate';
    }

    /**
     * Get Command Description
     */
    public function getDescription(): string
    {
        return 'Database migration management system';
    }

    /**
     * Get Command Help
     */
    public function getHelp(): string
    {
        return <<<HELP
    Usage:
      migrate <subcommand> [options]
    
    Available Subcommands:
      create <name>        Create a new migration file
      run                  Run pending migrations
      rollback             Rollback migrations
      status               Show migration status
      reset                Rollback all migrations
      refresh              Reset and re-run all migrations
      install              Create migrations tracking table
      make-seeder <name>   Create a new database seeder
      seed                 Run database seeders
      show <name>          Display specific migration content
    
    Options:
      -h, --help           Display this help message
      --force              Skip confirmation for production environment
      --dry-run, --pretend Show what would be executed without running
      --batch=N            Specify batch number for grouping migrations
      --path=<path>        Run migrations from custom directory
      --steps=N            Number of migrations to rollback (rollback only)
      --seeder=<name>      Run specific seeder (seed only)
    
    Examples:
      php glueful migrate create create_tasks_table
      php glueful migrate run --batch=2
      php glueful migrate run --path=/custom/migrations
      php glueful migrate rollback --steps=3
      php glueful migrate status
      php glueful migrate reset --force
      php glueful migrate refresh
      php glueful migrate make-seeder UserSeeder
      php glueful migrate seed --seeder=UserSeeder
      php glueful migrate show create_tasks_table
    HELP;
    }

    /**
     * Execute Migration Command
     */
    public function execute(array $args = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $subcommand = array_shift($args);

        return match ($subcommand) {
            'create' => $this->handleCreate($args),
            'run' => $this->handleRun($args),
            'rollback' => $this->handleRollback($args),
            'status' => $this->handleStatus($args),
            'reset' => $this->handleReset($args),
            'refresh' => $this->handleRefresh($args),
            'install' => $this->handleInstall($args),
            'make-seeder' => $this->handleMakeSeeder($args),
            'seed' => $this->handleSeed($args),
            'show' => $this->handleShow($args),
            default => $this->handleUnknownSubcommand($subcommand)
        };
    }

    /**
     * Handle migration creation
     */
    private function handleCreate(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Migration name is required. Use: php glueful migrate create <migration_name>");
            return Command::FAILURE;
        }

        $migrationName = $args[0];

        if (!$this->isValidMigrationName($migrationName)) {
            $this->error("Invalid migration name. Use snake_case format (e.g., create_tasks_table)");
            return Command::FAILURE;
        }

        try {
            $filePath = $this->createMigration($migrationName);
            $this->info("Migration created successfully:");
            $this->info("  " . $filePath);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create migration: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle migration execution
     */
    private function handleRun(array $args): int
    {
        $force = in_array('--force', $args);
        $dryRun = in_array('--dry-run', $args) || in_array('--pretend', $args);
        $batch = $this->extractOptionValue($args, '--batch');
        $customPath = $this->extractOptionValue($args, '--path');

        // Create custom migration manager if path is specified
        $migrationManager = $customPath ? new MigrationManager($customPath) : $this->migrationManager;

        if ($dryRun) {
            $this->info("\nPending Migrations:");
            $this->info("------------------");
            if ($customPath) {
                $this->info("Path: " . $customPath);
                $this->info("------------------");
            }
            $pendingMigrations = $migrationManager->getPendingMigrations();
            if (empty($pendingMigrations)) {
                $this->info("No pending migrations.");
                return Command::SUCCESS;
            }
            foreach ($pendingMigrations as $migration) {
                $this->info(" • " . basename($migration));
            }
            return Command::SUCCESS;
        }

        if (!$force && $this->isProduction()) {
            $this->error("You're in production! Use --force to proceed.");
            return Command::FAILURE;
        }

        try {
            if ($batch !== null) {
                $this->info("Running migrations in batch: " . $batch);
                // Note: Would need to enhance MigrationManager to support custom batch numbers
                $this->info("Custom batch numbers not yet implemented - using default batch assignment.");
            }

            if ($customPath) {
                $this->info("Running migrations from: " . $customPath);
            }

            $result = $migrationManager->migrate();
            $this->displayMigrationResult($result);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Migration failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle migration rollback
     */
    private function handleRollback(array $args): int
    {
        $force = in_array('--force', $args);
        $steps = $this->extractOptionValue($args, '--steps', 1);
        $batch = $this->extractOptionValue($args, '--batch');

        if (!$force && $this->isProduction()) {
            $this->error("You're in production! Use --force to proceed.");
            return Command::FAILURE;
        }

        try {
            if ($batch !== null) {
                $this->info("Rolling back to batch {$batch}...");
                $this->error("Batch rollback not yet implemented. Use --steps instead.");
                return Command::FAILURE;
            }

            $this->info("Rolling back {$steps} migration(s)...");
            $result = $this->migrationManager->rollback((int)$steps);
            $this->displayRollbackResult($result);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Rollback failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle migration status display
     */
    private function handleStatus(array $args): int
    {
        try {
            $pendingOnly = in_array('--pending', $args);
            $appliedOnly = in_array('--applied', $args);

            $pending = $this->migrationManager->getPendingMigrations();
            $applied = $this->getAppliedMigrations();

            if (!$appliedOnly) {
                $this->info("\n📋 Migration Status");
                $this->info("==================");

                if (!$pendingOnly) {
                    $this->info("\n✅ Applied Migrations:");
                    if (empty($applied)) {
                        $this->info("  No migrations applied yet.");
                    } else {
                        foreach ($applied as $migration) {
                            $this->info("  ✓ " . $migration);
                        }
                    }
                }

                $this->info("\n⏳ Pending Migrations:");
                if (empty($pending)) {
                    $this->info("  No pending migrations.");
                } else {
                    foreach ($pending as $migration) {
                        $this->info("  • " . basename($migration));
                    }
                }

                $this->info("\nSummary: " . count($applied) . " applied, " . count($pending) . " pending");
            } else {
                if ($pendingOnly) {
                    foreach ($pending as $migration) {
                        $this->info(basename($migration));
                    }
                } elseif ($appliedOnly) {
                    foreach ($applied as $migration) {
                        $this->info($migration);
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to get migration status: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle migration reset (rollback all)
     */
    private function handleReset(array $args): int
    {
        $force = in_array('--force', $args);

        if (!$force && $this->isProduction()) {
            $this->error("You're in production! Use --force to proceed.");
            return Command::FAILURE;
        }

        if (!$force) {
            $this->info("⚠️  This will rollback ALL migrations. Continue? (y/N)");
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);

            if (strtolower(trim($line)) !== 'y') {
                $this->info("Reset cancelled.");
                return Command::SUCCESS;
            }
        }

        try {
            $applied = $this->getAppliedMigrations();
            if (empty($applied)) {
                $this->info("No migrations to reset.");
                return Command::SUCCESS;
            }

            $this->info("Resetting " . count($applied) . " migration(s)...");
            $result = $this->migrationManager->rollback(count($applied));
            $this->displayRollbackResult($result);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Reset failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle migration refresh (reset + run)
     */
    private function handleRefresh(array $args): int
    {
        $force = in_array('--force', $args) || !$this->isProduction();

        $this->info("🔄 Refreshing migrations...");

        // First reset
        $resetResult = $this->handleReset($force ? ['--force'] : []);
        if ($resetResult !== Command::SUCCESS) {
            return $resetResult;
        }

        // Then run
        $this->info("\nRe-running migrations...");
        return $this->handleRun($force ? ['--force'] : []);
    }

    /**
     * Handle migration table installation
     */
    private function handleInstall(array $args): int
    {
        try {
            $this->info("✅ Migration table is ready.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to install migration table: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle seeder creation
     */
    private function handleMakeSeeder(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Seeder name is required. Use: php glueful migrate make-seeder <SeederName>");
            return Command::FAILURE;
        }

        $seederName = $args[0];

        // Ensure proper naming convention (PascalCase ending with 'Seeder')
        if (!str_ends_with($seederName, 'Seeder')) {
            $seederName .= 'Seeder';
        }

        try {
            $filePath = $this->createSeeder($seederName);
            $this->info("Seeder created successfully:");
            $this->info("  " . $filePath);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create seeder: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle database seeding
     */
    private function handleSeed(array $args): int
    {
        $force = in_array('--force', $args);
        $specificSeeder = $this->extractOptionValue($args, '--seeder');

        if (!$force && $this->isProduction()) {
            $this->error("You're in production! Use --force to proceed.");
            return Command::FAILURE;
        }

        try {
            if ($specificSeeder) {
                $this->info("Running seeder: " . $specificSeeder);
                $result = $this->runSeeder($specificSeeder);
            } else {
                $this->info("Running all seeders...");
                $result = $this->runAllSeeders();
            }

            if ($result['success']) {
                $this->info("✅ Seeding completed successfully.");
                foreach ($result['executed'] as $seeder) {
                    $this->info("  ✓ " . $seeder);
                }
            } else {
                $this->error("❌ Seeding failed.");
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->error("  • " . $error);
                    }
                }
            }

            return $result['success'] ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Seeding failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle migration content display
     */
    private function handleShow(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Migration name is required. Use: php glueful migrate show <migration_name>");
            return Command::FAILURE;
        }

        $migrationName = $args[0];

        try {
            $content = $this->getMigrationContent($migrationName);
            if ($content === null) {
                $this->error("Migration not found: " . $migrationName);
                return Command::FAILURE;
            }

            $this->info("📄 Migration: " . $migrationName);
            $this->info("================================");
            echo $content;
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to display migration: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle unknown subcommand
     */
    private function handleUnknownSubcommand(string $subcommand): int
    {
        $this->error("Unknown subcommand: {$subcommand}");
        $this->info("Available subcommands: create, run, rollback, status, reset, refresh, install, " .
                   "make-seeder, seed, show");
        $this->info("Use 'php glueful migrate --help' for more information.");
        return Command::FAILURE;
    }

    /**
     * Validate migration name format
     */
    private function isValidMigrationName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $name) === 1;
    }

    /**
     * Create migration file from template
     */
    private function createMigration(string $migrationName): string
    {
        $timestamp = date('Y_m_d_His');
        $fileName = sprintf('%s_%s.php', $timestamp, $migrationName);
        $className = $this->generateClassName($migrationName);
        $tableName = $this->extractTableName($migrationName);
        $description = $this->generateDescription($migrationName);

        $templatePath = __DIR__ . '/../Templates/Migrations/migration.php.tpl';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Migration template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $content = str_replace([
            '{{CLASS_NAME}}',
            '{{TABLE_NAME}}',
            '{{MIGRATION_DESCRIPTION}}'
        ], [
            $className,
            $tableName,
            $description
        ], $template);

        $migrationsDir = dirname(__DIR__, 3) . '/database/migrations';
        $filePath = $migrationsDir . '/' . $fileName;

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }

        if (file_exists($filePath)) {
            throw new \RuntimeException("Migration file already exists: {$fileName}");
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write migration file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Generate class name from migration name
     */
    private function generateClassName(string $migrationName): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $migrationName)));
    }

    /**
     * Extract table name from migration name
     */
    private function extractTableName(string $migrationName): string
    {
        if (preg_match('/^create_(.+)_table$/', $migrationName, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^add_\w+_to_(.+)$/', $migrationName, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^update_(.+)_/', $migrationName, $matches)) {
            return $matches[1];
        }

        $name = preg_replace('/^(create_|add_|update_|modify_|alter_)/', '', $migrationName);
        $name = preg_replace('/_table$/', '', $name);

        return $name;
    }

    /**
     * Generate human-readable description
     */
    private function generateDescription(string $migrationName): string
    {
        return ucfirst(str_replace('_', ' ', $migrationName));
    }

    /**
     * Extract option value from arguments
     */
    private function extractOptionValue(array $args, string $option, $default = null)
    {
        foreach ($args as $arg) {
            if (strpos($arg, $option . '=') === 0) {
                return substr($arg, strlen($option) + 1);
            }
        }

        $index = array_search($option, $args);
        if ($index !== false && isset($args[$index + 1])) {
            return $args[$index + 1];
        }

        return $default;
    }

    /**
     * Get applied migrations from database
     */
    private function getAppliedMigrations(): array
    {
        try {
            return $this->migrationManager->getAppliedMigrationsList();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Display migration results
     */
    private function displayMigrationResult(array $result, bool $isSingle = false): void
    {
        if (empty($result['applied']) && empty($result['failed'])) {
            $this->info("Nothing to migrate.");
            return;
        }

        if (!empty($result['applied'])) {
            $this->info("\n✅ Successfully applied:");
            foreach ($result['applied'] as $migration) {
                $this->info("  ✓ " . basename($migration));
            }
        }

        if (!empty($result['failed'])) {
            $this->error("\n❌ Failed migrations:");
            foreach ($result['failed'] as $migration) {
                $this->error("  ✗ " . basename($migration));
            }
        }

        $total = count($result['applied']) + count($result['failed']);
        $this->info("\nMigration complete: {$total} " . ($isSingle ? 'file' : 'files') . " processed");
    }

    /**
     * Display rollback results
     */
    private function displayRollbackResult(array $result): void
    {
        if (empty($result['reverted']) && empty($result['failed'])) {
            $this->info("Nothing to rollback.");
            return;
        }

        if (!empty($result['reverted'])) {
            $this->info("\n✅ Successfully reverted:");
            foreach ($result['reverted'] as $migration) {
                $this->info("  ↶ " . $migration);
            }
        }

        if (!empty($result['failed'])) {
            $this->error("\n❌ Failed rollbacks:");
            foreach ($result['failed'] as $migration) {
                $this->error("  ✗ " . $migration);
            }
        }

        $total = count($result['reverted']) + count($result['failed']);
        $this->info("\nRollback complete: {$total} migration(s) processed");
    }

    /**
     * Create seeder file from template
     */
    private function createSeeder(string $seederName): string
    {
        $seedersDir = dirname(__DIR__, 3) . '/database/seeders';
        $fileName = $seederName . '.php';
        $filePath = $seedersDir . '/' . $fileName;

        if (!is_dir($seedersDir)) {
            mkdir($seedersDir, 0755, true);
        }

        if (file_exists($filePath)) {
            throw new \RuntimeException("Seeder file already exists: {$fileName}");
        }

        $content = $this->generateSeederContent($seederName);

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write seeder file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Generate seeder file content
     */
    private function generateSeederContent(string $seederName): string
    {
        $tableName = strtolower(str_replace('Seeder', '', $seederName));

        return <<<PHP
<?php

namespace Glueful\Database\Seeders;

use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;

/**
 * {$seederName}
 *
 * Database seeder for {$tableName} table.
 * 
 * @package Glueful\Database\Seeders
 */
class {$seederName}
{
    /** @var QueryBuilder Database query builder */
    private QueryBuilder \$db;

    /**
     * Initialize seeder
     */
    public function __construct()
    {
        \$connection = new Connection();
        \$this->db = new QueryBuilder(\$connection->getPDO(), \$connection->getDriver());
    }

    /**
     * Run the database seeder
     */
    public function run(): void
    {
        // Add your seeding logic here
        // Example:
        // \$this->db->insert('{$tableName}', [
        //     'name' => 'Sample Record',
        //     'created_at' => date('Y-m-d H:i:s')
        // ]);
        
        echo "Seeding {$tableName} table...\n";
    }
}
PHP;
    }

    /**
     * Run specific seeder
     */
    private function runSeeder(string $seederName): array
    {
        $seedersDir = dirname(__DIR__, 3) . '/database/seeders';
        $seederFile = $seedersDir . '/' . $seederName . '.php';

        if (!file_exists($seederFile)) {
            return [
                'success' => false,
                'errors' => ["Seeder not found: {$seederName}"],
                'executed' => []
            ];
        }

        try {
            require_once $seederFile;
            $seederClass = "Glueful\\Database\\Seeders\\{$seederName}";

            if (!class_exists($seederClass)) {
                return [
                    'success' => false,
                    'errors' => ["Seeder class not found: {$seederClass}"],
                    'executed' => []
                ];
            }

            $seeder = new $seederClass();
            $seeder->run();

            return [
                'success' => true,
                'errors' => [],
                'executed' => [$seederName]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()],
                'executed' => []
            ];
        }
    }

    /**
     * Run all seeders
     */
    private function runAllSeeders(): array
    {
        $seedersDir = dirname(__DIR__, 3) . '/database/seeders';

        if (!is_dir($seedersDir)) {
            return [
                'success' => false,
                'errors' => ['Seeders directory not found'],
                'executed' => []
            ];
        }

        $seederFiles = glob($seedersDir . '/*.php');
        $executed = [];
        $errors = [];

        foreach ($seederFiles as $file) {
            $seederName = basename($file, '.php');
            $result = $this->runSeeder($seederName);

            if ($result['success']) {
                $executed = array_merge($executed, $result['executed']);
            } else {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        return [
            'success' => empty($errors),
            'errors' => $errors,
            'executed' => $executed
        ];
    }

    /**
     * Get migration file content
     */
    private function getMigrationContent(string $migrationName): ?string
    {
        $migrationsDir = dirname(__DIR__, 3) . '/database/migrations';

        // Try exact filename first
        $exactFile = $migrationsDir . '/' . $migrationName;
        if (file_exists($exactFile)) {
            return file_get_contents($exactFile);
        }

        // Try with .php extension
        $phpFile = $migrationsDir . '/' . $migrationName . '.php';
        if (file_exists($phpFile)) {
            return file_get_contents($phpFile);
        }

        // Search for files containing the name
        $files = glob($migrationsDir . '/*' . $migrationName . '*.php');
        if (!empty($files)) {
            return file_get_contents($files[0]);
        }

        return null;
    }
}
