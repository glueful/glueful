<?php

namespace Glueful\Console\Commands\Database;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Glueful\Database\Schema\SchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

/**
 * Database Status Command
 * - Database-agnostic queries using QueryBuilder
 * - Comprehensive database diagnostics with tabular display
 * - Connection health check with detailed error reporting
 * - Server information and version detection
 * - Database metrics and table statistics
 * - Optional detailed table information
 * - Enhanced output formatting with tables and progress indicators
 * @package Glueful\Console\Commands\Database
 */
#[AsCommand(
    name: 'db:status',
    description: 'Show database connection status and statistics'
)]
class StatusCommand extends BaseCommand
{
    private SchemaManager $schema;
    private Connection $connection;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Show database connection status and statistics')
             ->setHelp('This command displays comprehensive database information including connection status, ' .
                       'server info, and table statistics.')
             ->addOption(
                 'details',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show detailed table information'
             )
             ->addOption(
                 'format',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, csv)',
                 'table'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $showDetails = $input->getOption('details');

        try {
            $this->connection = new Connection();
            $this->schema = $this->connection->getSchemaManager();

            $this->displayConnectionStatus();
            $this->displayServerInfo();
            $this->displayDatabaseMetrics();

            if ($showDetails) {
                $this->displayTableDetails();
            }

            $this->displayConnectionPoolInfo();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->displayConnectionError($e);
            return self::FAILURE;
        }
    }

    private function displayConnectionStatus(): void
    {
        $this->info('Database Connection Status');
        $this->line('');

        // Test connection
        try {
            $this->connection->getPDO();
            $status = '<info>✓ Connected</info>';
        } catch (\Exception) {
            $status = '<error>✗ Disconnected</error>';
        }

        $headers = ['Property', 'Value'];
        $rows = [
            ['Status', $status],
            ['Driver', $this->connection->getDriverName()],
            ['Host', config('database.mysql.host', config('database.pgsql.host', 'Unknown'))],
            ['Database', config('database.mysql.db', config('database.pgsql.db', 'Unknown'))],
            ['User', config('database.mysql.user', config('database.pgsql.user', 'Unknown'))],
        ];

        $this->table($headers, $rows);
    }

    private function displayServerInfo(): void
    {
        $this->line('');
        $this->info('Server Information');

        try {
            $pdo = $this->connection->getPDO();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $driverName = $this->connection->getDriverName();

            $headers = ['Property', 'Value'];
            $rows = [
                ['Version', $version],
                ['Driver', ucfirst($driverName)],
                ['Client Version', $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION)],
                ['Connection Status', $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)],
            ];

            $this->table($headers, $rows);
        } catch (\Exception $e) {
            $this->warning('Server information not available: ' . $e->getMessage());
        }
    }

    private function displayDatabaseMetrics(): void
    {
        $this->line('');
        $this->info('Database Metrics');

        try {
            $driverName = $this->connection->getDriverName();

            // Get table count using SchemaManager (database-agnostic approach)
            // This avoids raw SQL queries and uses the proper abstraction layer
            $tables = $this->schema->getTables();
            $tableCount = count($tables);

            // Calculate total database size using SchemaManager methods
            $totalSize = 0;
            foreach ($tables as $table) {
                try {
                    $totalSize += $this->schema->getTableSize($table);
                } catch (\Exception $e) {
                    // Skip table if size calculation fails
                    continue;
                }
            }

            $headers = ['Metric', 'Value'];
            $rows = [
                ['Total Tables', $tableCount],
                ['Database Engine', ucfirst($driverName)],
                ['Database Size', $this->formatBytes($totalSize)],
                ['Connection Pooling', config('database.pooling.enabled', false) ? 'Enabled' : 'Disabled'],
            ];

            $this->table($headers, $rows);
        } catch (\Exception $e) {
            $this->warning('Database metrics not available: ' . $e->getMessage());
        }
    }

    private function displayTableDetails(): void
    {
        $this->line('');
        $this->info('Table Details');

        try {
            $tables = $this->schema->getTables();
            $tableData = [];

            foreach ($tables as $table) {
                $size = $this->schema->getTableSize($table);
                $rowCount = $this->schema->getTableRowCount($table);

                $tableData[] = [
                    'name' => $table,
                    'size' => $size,
                    'rows' => $rowCount,
                ];
            }

            // Sort by size descending
            usort($tableData, function ($a, $b) {
                return $b['size'] <=> $a['size'];
            });

            $headers = ['Table', 'Size', 'Row Count'];
            $rows = [];

            foreach ($tableData as $data) {
                $rows[] = [
                    $data['name'],
                    $this->formatBytes($data['size']),
                    number_format($data['rows']),
                ];
            }

            $this->table($headers, $rows);

            // Show top 5 largest tables
            $this->line('');
            $this->info('Top 5 Largest Tables:');
            $top5 = array_slice($tableData, 0, 5);

            foreach ($top5 as $index => $data) {
                $this->line(sprintf(
                    '%d. %s (%s)',
                    $index + 1,
                    $data['name'],
                    $this->formatBytes($data['size'])
                ));
            }
        } catch (\Exception $e) {
            $this->warning('Table details not available: ' . $e->getMessage());
        }
    }

    private function displayConnectionPoolInfo(): void
    {
        $this->line('');
        $this->info('Connection Pool Information');

        $poolEnabled = config('database.pooling.enabled', false);

        if ($poolEnabled) {
            $headers = ['Property', 'Value'];
            $rows = [
                ['Pool Enabled', '<info>Yes</info>'],
                ['Min Connections', config('database.pooling.min_connections', 5)],
                ['Max Connections', config('database.pooling.max_connections', 20)],
                ['Pool Manager', Connection::getPoolManager() ? '<info>Active</info>' : '<comment>Inactive</comment>'],
            ];
            $this->table($headers, $rows);
        } else {
            $this->line('Connection pooling is not enabled.');
            $this->tip('Enable connection pooling in your database configuration for better performance.');
            $this->tip('Set database.pooling.enabled=true in your configuration.');
        }
    }

    private function displayConnectionError(\Exception $e): void
    {
        $this->error('Database Connection Failed');
        $this->line('');

        $headers = ['Property', 'Value'];
        $rows = [
            ['Status', '<error>✗ Disconnected</error>'],
            ['Error', $e->getMessage()],
            ['Driver', config('database.driver', 'Unknown')],
            ['Host', config('database.host', 'Unknown')],
            ['Database', config('database.database', 'Unknown')],
        ];

        $this->table($headers, $rows);

        $this->line('');
        $this->tip('Check your database configuration in .env file');
        $this->tip('Ensure the database server is running and accessible');
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
