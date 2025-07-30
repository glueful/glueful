<?php

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migration Create Command
 * Creates new database migration files with enhanced validation:
 * - Proper argument validation
 * - Migration name format checking
 * - Template-based file generation
 * - Interactive feedback
 * - FileFinder and FileManager integration for safe file operations
 * @package Glueful\Console\Commands\Migrate
 */
#[AsCommand(
    name: 'migrate:create',
    description: 'Create a new database migration file'
)]
class CreateCommand extends BaseCommand
{
    private FileFinder $fileFinder;
    private FileManager $fileManager;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Create a new database migration file')
             ->setHelp('This command generates a new migration file with the specified name.')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the migration (use snake_case format, e.g., create_tasks_table)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $migrationName = $input->getArgument('name');

        // Validate migration name format
        if (!$this->isValidMigrationName($migrationName)) {
            $this->error('Invalid migration name format.');
            $this->line('');
            $this->info('Migration names should use snake_case format.');
            $this->line('Examples:');
            $this->line('  • create_users_table');
            $this->line('  • add_email_to_users_table');
            $this->line('  • drop_old_logs_table');

            return self::FAILURE;
        }

        try {
            $this->info(sprintf('Creating migration: %s', $migrationName));

            $filePath = $this->createMigration($migrationName);

            $this->success('Migration created successfully!');
            $this->line('');
            $this->info(sprintf('File: %s', $filePath));
            $this->tip('Edit the migration file to add your database changes, then run: php glueful migrate:run');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create migration: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function isValidMigrationName(string $name): bool
    {
        // Check if name matches snake_case pattern
        return preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $name) === 1;
    }

    private function createMigration(string $migrationName): string
    {
        // Get migrations directory
        $migrationsDir = dirname(__DIR__, 4) . '/database/migrations';

        // Get next migration number
        $nextNumber = $this->getNextMigrationNumber($migrationsDir);
        $fileName = sprintf('%03d_%s.php', $nextNumber, $migrationName);
        $className = $this->formatClassName($migrationName);
        $filePath = $migrationsDir . '/' . $fileName;

        // Ensure directory exists using FileManager
        if (!$this->fileManager->exists($migrationsDir)) {
            $this->fileManager->createDirectory($migrationsDir);
        }

        // Check if file already exists using FileManager
        if ($this->fileManager->exists($filePath)) {
            throw new \Exception("Migration file already exists: {$fileName}");
        }

        // Generate migration content
        $content = $this->generateMigrationContent($className, $migrationName);

        // Write file using FileManager
        $success = $this->fileManager->writeFile($filePath, $content);

        if (!$success) {
            throw new \Exception('Failed to write migration file');
        }

        return $filePath;
    }

    private function formatClassName(string $migrationName): string
    {
        // Convert snake_case to PascalCase
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $migrationName)));
    }

    private function getNextMigrationNumber(string $migrationsDir): int
    {
        if (!$this->fileManager->exists($migrationsDir)) {
            return 1;
        }

        // Use FileFinder to get migration files
        $migrationFiles = $this->fileFinder->findMigrations($migrationsDir);
        $maxNumber = 0;

        foreach ($migrationFiles as $file) {
            if (preg_match('/^(\d{3})_/', $file->getFilename(), $matches)) {
                $number = (int) $matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        return $maxNumber + 1;
    }

    private function generateMigrationContent(string $className, string $migrationName): string
    {
        $timestamp = date('Y-m-d H:i:s');

        return <<<PHP
<?php

namespace Glueful\\Database\\Migrations;

use Glueful\\Database\\Migrations\\MigrationInterface;
use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

/**
 * {$className} Migration
 *
 * Migration: {$migrationName}
 * Created: {$timestamp}
 *
 * @package Glueful\\Database\\Migrations
 */
class {$className} implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * @param SchemaBuilderInterface \$schema Database schema builder
     */
    public function up(SchemaBuilderInterface \$schema): void
    {
        // Create table with fluent interface
        \$schema->createTable('table_name', function (\$table) {
            // Primary key
            \$table->bigInteger('id')->primary()->autoIncrement();
            
            // Common columns
            \$table->string('uuid', 12)->unique();
            \$table->string('name', 255);
            \$table->text('description')->nullable();
            \$table->boolean('is_active')->default(true);
            \$table->integer('sort_order')->default(0);
            
            // JSON columns
            \$table->json('metadata')->nullable();
            
            // Timestamps
            \$table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            \$table->timestamp('updated_at')->nullable();
            
            // Indexes
            \$table->index('name');
            \$table->index('is_active');
            \$table->index(['is_active', 'sort_order']);
        });

        // Or create table with foreign keys
        // \$schema->table('child_table')
        //     ->id()
        //     ->foreignId('parent_id')->constrained('parent_table')->cascadeOnDelete()
        //     ->string('title')
        //     ->timestamps()
        //     ->create();
    }

    /**
     * Reverse the migration
     *
     * @param SchemaBuilderInterface \$schema Database schema builder
     */
    public function down(SchemaBuilderInterface \$schema): void
    {
        // Drop table
        \$schema->dropTable('table_name');
        
        // Or use dropIfExists for safety
        // \$schema->dropIfExists('table_name');
    }

    /**
     * Get migration description
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return '{$migrationName}';
    }
}
PHP;
    }

    private function initializeServices(): void
    {
        $this->fileFinder = $this->getService(FileFinder::class);
        $this->fileManager = $this->getService(FileManager::class);
    }
}
