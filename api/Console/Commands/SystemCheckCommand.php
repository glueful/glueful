<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;

/**
 * System Check Command
 *
 * Validates framework installation and configuration
 * for developers using the framework.
 */
class SystemCheckCommand extends Command
{
    public function getName(): string
    {
        return 'system:check';
    }

    public function getDescription(): string
    {
        return 'Validate framework installation and configuration';
    }

    public function getHelp(): string
    {
        return <<<HELP
Check system requirements and configuration:

Usage:
  php glueful system:check [options]

Options:
  --verbose    Show detailed information
  --fix        Attempt to fix common issues
  --production Check production readiness

Examples:
  php glueful system:check
  php glueful system:check --verbose
  php glueful system:check --production
HELP;
    }

    public function execute(array $args = []): int
    {
        if (isset($args[0]) && in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $this->info("🔍 Glueful Framework System Check");
        $this->line("");

        $verbose = in_array('--verbose', $args);
        $fix = in_array('--fix', $args);
        $production = in_array('--production', $args);

        $checks = [
            'PHP Version' => $this->checkPhpVersion(),
            'Extensions' => $this->checkPhpExtensions(),
            'Permissions' => $this->checkPermissions($fix),
            'Configuration' => $this->checkConfiguration($production),
            'Database' => $this->checkDatabase(),
            'Security' => $this->checkSecurity($production)
        ];

        $passed = 0;
        $total = count($checks);

        foreach ($checks as $category => $result) {
            $status = $result['passed'] ? '✅' : '❌';
            $this->line(sprintf("%-15s %s %s", $category, $status, $result['message']));

            if ($verbose && !empty($result['details'])) {
                foreach ($result['details'] as $detail) {
                    $this->line("                  $detail");
                }
            }

            if ($result['passed']) {
                $passed++;
            }
        }

        $this->line("");
        if ($passed === $total) {
            $this->success("🎉 All checks passed! Framework is ready.");
            return 0;
        } else {
            $this->warning("⚠️  $passed/$total checks passed. Please address the issues above.");
            if (!$verbose) {
                $this->tip("Run with --verbose for more details");
            }
            return 1;
        }
    }


    private function checkPhpVersion(): array
    {
        $required = '8.2.0';
        $current = PHP_VERSION;
        $passed = version_compare($current, $required, '>=');

        return [
            'passed' => $passed,
            'message' => $passed ?
                "PHP $current (>= $required)" :
                "PHP $current (requires >= $required)",
            'details' => $passed ? [] : [
                "Update PHP to version $required or higher",
                "Current version: $current"
            ]
        ];
    }

    private function checkPhpExtensions(): array
    {
        $required = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'curl'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        return [
            'passed' => empty($missing),
            'message' => empty($missing) ?
                'All required extensions loaded' :
                'Missing extensions: ' . implode(', ', $missing),
            'details' => empty($missing) ? [] : array_map(
                fn($ext) => "Install php-$ext extension",
                $missing
            )
        ];
    }

    private function checkPermissions(bool $fix = false): array
    {
        $dirs = [
            'storage' => 0755,
            'storage/logs' => 0755,
            'storage/cache' => 0755,
            'storage/sessions' => 0755
        ];

        $issues = [];
        $baseDir = dirname(__DIR__, 3);

        foreach ($dirs as $dir => $requiredPerms) {
            $path = "$baseDir/$dir";

            if (!is_dir($path)) {
                if ($fix) {
                    mkdir($path, $requiredPerms, true);
                    $this->line("Created directory: $dir");
                } else {
                    $issues[] = "Directory missing: $dir";
                }
                continue;
            }

            if (!is_writable($path)) {
                if ($fix) {
                    chmod($path, $requiredPerms);
                    $this->line("Fixed permissions: $dir");
                } else {
                    $issues[] = "Directory not writable: $dir";
                }
            }
        }

        return [
            'passed' => empty($issues),
            'message' => empty($issues) ?
                'All directories writable' :
                count($issues) . ' permission issues',
            'details' => $fix ? [] : array_merge($issues, [
                'Run with --fix to attempt automatic fixes'
            ])
        ];
    }

    private function checkConfiguration(bool $production): array
    {
        $issues = [];

        // Check for .env file
        $envPath = dirname(__DIR__, 3) . '/.env';
        if (!file_exists($envPath)) {
            $issues[] = '.env file not found - copy .env.example';
        }

        // Production-specific checks
        if ($production) {
            $debugEnabled = getenv('APP_DEBUG') === 'true';
            if ($debugEnabled) {
                $issues[] = 'APP_DEBUG should be false in production';
            }

            $jwtSecret = getenv('JWT_SECRET');
            if (!$jwtSecret || strlen($jwtSecret) < 32) {
                $issues[] = 'JWT_SECRET must be set and at least 32 characters';
            }
        }

        return [
            'passed' => empty($issues),
            'message' => empty($issues) ?
                'Configuration valid' :
                count($issues) . ' configuration issues',
            'details' => $issues
        ];
    }

    private function checkSecurity(bool $production): array
    {
        $issues = [];

        // Check if running as root (bad practice)
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $issues[] = 'Running as root user (security risk)';
        }

        // Check for common security files
        $baseDir = dirname(__DIR__, 3);
        $publicEnv = "$baseDir/public/.env";
        if (file_exists($publicEnv)) {
            $issues[] = '.env file in public directory (critical security risk)';
        }

        // Production-specific security checks
        if ($production) {
            $publicIndex = "$baseDir/public/index.php";
            if (file_exists($publicIndex)) {
                $content = file_get_contents($publicIndex);
                if (strpos($content, 'error_reporting(E_ALL)') !== false) {
                    $issues[] = 'Error reporting enabled in production';
                }
            }
        }

        return [
            'passed' => empty($issues),
            'message' => empty($issues) ?
                'Security checks passed' :
                count($issues) . ' security issues found',
            'details' => $issues
        ];
    }

    private function checkDatabase(): array
    {
        $issues = [];
        $details = [];

        try {
            // 1. Test basic database connection using your Connection class
            $connection = new \Glueful\Database\Connection();
            $pdo = $connection->getPDO();
            $driver = $connection->getDriver();

            $details[] = "Driver: " . get_class($driver);
            $details[] = "Database: " . ($pdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS) ?: 'Connected');

            // 2. Test Schema Manager
            $schemaManager = $connection->getSchemaManager();
            $version = $schemaManager->getVersion();
            $details[] = "Database version: $version";

            // 3. Verify migrations table exists and is accessible
            $migrationsManager = new \Glueful\Database\Migrations\MigrationManager();

            // Check if migrations table exists by trying to query it
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM migrations");
            $stmt->execute();
            $migrationCount = $stmt->fetchColumn();
            $details[] = "Applied migrations: $migrationCount";

            // 4. Test QueryBuilder functionality
            $queryBuilder = new \Glueful\Database\QueryBuilder($pdo, $driver);

            // Simple test query to verify QueryBuilder works
            $testResult = $queryBuilder->count('migrations');
            if ($testResult !== $migrationCount) {
                $issues[] = "QueryBuilder count mismatch with direct query";
            }

            // 5. Test database-specific features based on driver
            $this->testDatabaseSpecificFeatures($pdo, $driver, $schemaManager, $details, $issues);

            // 6. Check foreign key constraints are enabled (important for data integrity)
            $this->checkForeignKeySupport($schemaManager, $details, $issues);

            // 7. Verify write permissions by testing a transaction
            $this->testTransactionSupport($pdo, $details, $issues);

            // 8. Test Query Cache Service if available
            $this->testQueryCacheService($details, $issues);
        } catch (\PDOException $e) {
            return [
                'passed' => false,
                'message' => 'Database connection failed',
                'details' => [
                    'PDO Error: ' . $e->getMessage(),
                    'Check database credentials in .env file',
                    'Ensure database server is running',
                    'Verify database exists and user has permissions'
                ]
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Database system error',
                'details' => [
                    'Error: ' . $e->getMessage(),
                    'Check database configuration and permissions'
                ]
            ];
        }

        return [
            'passed' => empty($issues),
            'message' => empty($issues) ?
                'Database system operational' :
                count($issues) . ' database issues found',
            'details' => array_merge($details, $issues)
        ];
    }

    /**
     * Test database-specific features based on the driver
     */
    private function testDatabaseSpecificFeatures($pdo, $driver, $schemaManager, &$details, &$issues): void
    {
        $driverClass = get_class($driver);

        switch (true) {
            case str_contains($driverClass, 'MySQL'):
                $this->testMySQLFeatures($pdo, $schemaManager, $details, $issues);
                break;

            case str_contains($driverClass, 'PostgreSQL'):
                $this->testPostgreSQLFeatures($pdo, $schemaManager, $details, $issues);
                break;

            case str_contains($driverClass, 'SQLite'):
                $this->testSQLiteFeatures($pdo, $schemaManager, $details, $issues);
                break;

            default:
                $details[] = "Unknown database driver: $driverClass";
        }
    }

    /**
     * Test MySQL-specific features
     */
    private function testMySQLFeatures($pdo, $schemaManager, &$details, &$issues): void
    {
        try {
            // Check MySQL version compatibility
            $version = $schemaManager->getVersion();
            if (version_compare($version, '5.7.0', '<')) {
                $issues[] = "MySQL version $version is below recommended 5.7.0+";
            }

            // Check if InnoDB is available (required for foreign keys)
            $engines = $pdo->query("SHOW ENGINES")->fetchAll(\PDO::FETCH_ASSOC);
            $innodbAvailable = false;
            foreach ($engines as $engine) {
                if ($engine['Engine'] === 'InnoDB' && in_array($engine['Support'], ['YES', 'DEFAULT'])) {
                    $innodbAvailable = true;
                    break;
                }
            }

            if (!$innodbAvailable) {
                $issues[] = "InnoDB storage engine not available (required for foreign keys)";
            } else {
                $details[] = "InnoDB storage engine: Available";
            }

            // Check current foreign key setting
            $fkCheck = $pdo->query("SELECT @@FOREIGN_KEY_CHECKS")->fetchColumn();
            $details[] = "Foreign key checks: " . ($fkCheck ? 'Enabled' : 'Disabled');
        } catch (\Exception $e) {
            $issues[] = "MySQL feature check failed: " . $e->getMessage();
        }
    }

    /**
     * Test PostgreSQL-specific features
     */
    private function testPostgreSQLFeatures($pdo, $schemaManager, &$details, &$issues): void
    {
        try {
            // Check PostgreSQL version compatibility
            $version = $schemaManager->getVersion();
            if (version_compare($version, '12.0', '<')) {
                $issues[] = "PostgreSQL version $version is below recommended 12.0+";
            }

            // Check if we have necessary privileges
            $query = "SELECT has_database_privilege(current_user, current_database(), 'CREATE')";
            $result = $pdo->query($query)->fetchColumn();
            if (!$result) {
                $issues[] = "User lacks CREATE privileges on database";
            } else {
                $details[] = "CREATE privileges: Available";
            }

            // Check current replication role (affects foreign keys)
            $role = $pdo->query("SHOW session_replication_role")->fetchColumn();
            $details[] = "Replication role: $role";

            if ($role !== 'origin') {
                $issues[] = "Session replication role is '$role' (should be 'origin' for normal operation)";
            }
        } catch (\Exception $e) {
            $issues[] = "PostgreSQL feature check failed: " . $e->getMessage();
        }
    }

    /**
     * Test SQLite-specific features
     */
    private function testSQLiteFeatures($pdo, $schemaManager, &$details, &$issues): void
    {
        try {
            // Check SQLite version
            $version = $schemaManager->getVersion();
            if (version_compare($version, '3.24.0', '<')) {
                $issues[] = "SQLite version $version is below recommended 3.24.0+";
            }

            // Check if foreign keys are enabled
            $fkEnabled = $pdo->query("PRAGMA foreign_keys")->fetchColumn();
            $details[] = "Foreign keys: " . ($fkEnabled ? 'Enabled' : 'Disabled');

            // Check write access to database file
            $dbPath = $pdo->query("PRAGMA database_list")->fetch(\PDO::FETCH_ASSOC);
            if ($dbPath && $dbPath['file'] !== '') {
                if (!is_writable($dbPath['file'])) {
                    $issues[] = "Database file is not writable: " . $dbPath['file'];
                } else {
                    $details[] = "Database file: Writable";
                }
            }
        } catch (\Exception $e) {
            $issues[] = "SQLite feature check failed: " . $e->getMessage();
        }
    }

    /**
     * Check foreign key constraint support
     */
    private function checkForeignKeySupport($schemaManager, &$details, &$issues): void
    {
        try {
            // Temporarily disable and re-enable foreign keys to test functionality
            $schemaManager->disableForeignKeyChecks();
            $schemaManager->enableForeignKeyChecks();
            $details[] = "Foreign key management: Functional";
        } catch (\Exception $e) {
            $issues[] = "Foreign key management failed: " . $e->getMessage();
        }
    }

    /**
     * Test transaction support
     */
    private function testTransactionSupport($pdo, &$details, &$issues): void
    {
        try {
            // Test transaction functionality
            $pdo->beginTransaction();

            // Create a temporary test table
            $testTable = 'glueful_test_' . uniqid();
            $pdo->exec("CREATE TEMPORARY TABLE $testTable (id INTEGER PRIMARY KEY, test_data VARCHAR(50))");

            // Insert test data
            $stmt = $pdo->prepare("INSERT INTO $testTable (test_data) VALUES (?)");
            $stmt->execute(['test_transaction']);

            // Rollback to test transaction support
            $pdo->rollBack();

            $details[] = "Transaction support: Functional";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $issues[] = "Transaction support failed: " . $e->getMessage();
        }
    }

    /**
     * Test Query Cache Service availability
     */
    private function testQueryCacheService(&$details, &$issues): void
    {
        try {
            // Check if cache is configured
            $cacheDriver = getenv('CACHE_DRIVER') ?: 'file';
            $details[] = "Cache driver: $cacheDriver";

            // If Redis is configured, test connection
            if ($cacheDriver === 'redis') {
                $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
                $redisPort = getenv('REDIS_PORT') ?: 6379;

                $connection = @fsockopen($redisHost, (int)$redisPort, $errno, $errstr, 1);
                if ($connection) {
                    fclose($connection);
                    $details[] = "Redis connection: Available";
                } else {
                    $issues[] = "Redis connection failed: $errstr";
                }
            }
        } catch (\Exception $e) {
            $issues[] = "Cache system check failed: " . $e->getMessage();
        }
    }
}
