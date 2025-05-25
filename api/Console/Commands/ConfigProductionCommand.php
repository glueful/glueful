<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Security\SecurityManager;
use Glueful\Services\HealthService;
use Glueful\Security\RandomStringGenerator;

/**
 * Production Configuration Command
 *
 * Dedicated command for production environment configuration management:
 * - Production readiness validation and scoring
 * - Automated security fix suggestions and application
 * - Production template management
 * - Environment migration assistance
 * - Comprehensive production audit reporting
 */
class ConfigProductionCommand extends Command
{
    public function getName(): string
    {
        return 'config:production';
    }

    public function getDescription(): string
    {
        return 'Manage and validate production configuration settings';
    }

    public function getHelp(): string
    {
        return <<<HELP
Production Configuration Management:

Usage:
  php glueful config:production [options]

Options:
  --check              Check production readiness and show issues
  --score              Show production readiness score (0-100)
  --fix                Apply automatic fixes for detected issues
  --apply-template     Apply production template to current .env
  --migrate ENV        Migrate configuration to specified environment
  --audit              Generate comprehensive production audit report
  --suggestions        Show detailed fix suggestions without applying
  --backup             Create backup before making changes (default: true)
  --force              Skip confirmations and apply changes directly
  --quiet              Suppress output except errors

Examples:
  php glueful config:production --check
  php glueful config:production --score
  php glueful config:production --fix --backup
  php glueful config:production --apply-template --force
  php glueful config:production --migrate production
  php glueful config:production --audit > production-audit.txt
HELP;
    }

    public function execute(array $args = []): int
    {
        if (isset($args[0]) && in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $check = in_array('--check', $args);
        $score = in_array('--score', $args);
        $fix = in_array('--fix', $args);
        $applyTemplate = in_array('--apply-template', $args);
        $suggestions = in_array('--suggestions', $args);
        $audit = in_array('--audit', $args);
        $backup = !in_array('--no-backup', $args); // Default to true
        $force = in_array('--force', $args);
        $quiet = in_array('--quiet', $args);

        // Handle migrate option
        $migrate = null;
        $migrateIndex = array_search('--migrate', $args);
        if ($migrateIndex !== false && isset($args[$migrateIndex + 1])) {
            $migrate = $args[$migrateIndex + 1];
        }

        if (!$quiet) {
            $this->showHeader();
        }

        // Default action if no specific option provided
        if (!$check && !$score && !$fix && !$applyTemplate && !$suggestions && !$audit && !$migrate) {
            $check = true; // Default to check
        }

        $exitCode = Command::SUCCESS;

        // Execute requested actions
        if ($check) {
            $exitCode = max($exitCode, $this->runProductionCheck($quiet));
        }

        if ($score) {
            $this->showProductionScore($quiet);
        }

        if ($suggestions) {
            $this->showFixSuggestions($quiet);
        }

        if ($fix) {
            $exitCode = max($exitCode, $this->applyAutomaticFixes($backup, $force, $quiet));
        }

        if ($applyTemplate) {
            $exitCode = max($exitCode, $this->applyProductionTemplate($backup, $force, $quiet));
        }

        if ($migrate) {
            $exitCode = max($exitCode, $this->migrateEnvironment($migrate, $backup, $force, $quiet));
        }

        if ($audit) {
            $this->generateAuditReport($quiet);
        }

        return $exitCode;
    }

    private function showHeader(): void
    {
        $this->info('🔧 Glueful Production Configuration Manager');
        $this->line('');
    }

    /**
     * Run production readiness check
     */
    private function runProductionCheck(bool $quiet): int
    {
        if (!$quiet) {
            $this->info('🔍 Running production readiness check...');
            $this->line('');
        }

        $validation = SecurityManager::validateProductionEnvironment();
        $health = HealthService::getOverallHealth();

        if (!$validation['is_production']) {
            if (!$quiet) {
                $this->warning('⚠️  Current environment is not set to production');
                $this->line("Environment: {$validation['environment']}");
                $this->line('Set APP_ENV=production to enable production validation.');
            }
            return Command::SUCCESS;
        }

        $hasIssues = false;

        // Show warnings (critical issues)
        if (!empty($validation['warnings'])) {
            $hasIssues = true;
            if (!$quiet) {
                $this->error('🚨 Critical Issues Found:');
                foreach ($validation['warnings'] as $warning) {
                    $this->line("  ❌ $warning");
                }
                $this->line('');
            }
        }

        // Show recommendations
        if (!empty($validation['recommendations'])) {
            if (!$quiet) {
                $this->warning('💡 Recommendations:');
                foreach ($validation['recommendations'] as $recommendation) {
                    $this->line("  • $recommendation");
                }
                $this->line('');
            }
        }

        // Show overall health
        if ($health['status'] !== 'ok') {
            $hasIssues = true;
            if (!$quiet) {
                $this->error('🔧 System Health Issues:');
                foreach ($health['checks'] as $checkName => $check) {
                    if ($check['status'] !== 'ok') {
                        $this->line("  ❌ $checkName: {$check['message']}");
                    }
                }
                $this->line('');
            }
        }

        if (!$hasIssues) {
            if (!$quiet) {
                $this->success('✅ Production configuration looks good!');
                $this->line('');
            }
            return Command::SUCCESS;
        } else {
            if (!$quiet) {
                $this->line('Run with --fix to apply automatic fixes.');
                $this->line('Run with --suggestions to see detailed fix instructions.');
            }
            return Command::FAILURE;
        }
    }

    /**
     * Show production readiness score
     */
    private function showProductionScore(bool $quiet): void
    {
        if (!$quiet) {
            $this->info('📊 Production Readiness Score');
            $this->line('');
        }

        $scoreData = SecurityManager::getProductionReadinessScore();

        if (!$quiet) {
            $scoreColor = match (true) {
                $scoreData['score'] >= 90 => 'success',
                $scoreData['score'] >= 75 => 'info',
                $scoreData['score'] >= 60 => 'warning',
                default => 'error'
            };

            $this->$scoreColor("Score: {$scoreData['score']}/100 ({$scoreData['status']})");
            $this->line('');
            $this->line($scoreData['message']);

            if (isset($scoreData['breakdown'])) {
                $this->line('');
                $this->info('Breakdown:');
                $this->line("  • Critical Issues: {$scoreData['breakdown']['critical_issues']}");
                $this->line("  • Recommendations: {$scoreData['breakdown']['recommendations']}");
                $this->line("  • Total Checks: {$scoreData['breakdown']['total_checks']}");
            }
        } else {
            // Quiet mode: just output the score
            echo $scoreData['score'] . "\n";
        }
    }

    /**
     * Show detailed fix suggestions
     */
    private function showFixSuggestions(bool $quiet): void
    {
        if (!$quiet) {
            $this->info('🔧 Production Fix Suggestions');
            $this->line('');
        }

        SecurityManager::displayFixSuggestions();
    }

    /**
     * Apply automatic fixes for detected issues
     */
    private function applyAutomaticFixes(bool $backup, bool $force, bool $quiet): int
    {
        if (!$quiet) {
            $this->info('🔧 Applying automatic fixes...');
            $this->line('');
        }

        $fixes = SecurityManager::getEnvironmentFixSuggestions();
        $autoFixable = array_filter($fixes, fn($fix) => $this->canAutoFix($fix));

        if (empty($autoFixable)) {
            if (!$quiet) {
                $this->warning('No automatic fixes available.');
                $this->line('Manual configuration required for detected issues.');
            }
            return Command::SUCCESS;
        }

        if (!$force && !$quiet) {
            $this->warning('The following fixes will be applied:');
            foreach ($autoFixable as $fix) {
                $this->line("  • {$fix['fix']}");
            }
            $this->line('');

            $proceed = $this->prompt('Proceed with automatic fixes? (y/n)', 'y');
            if (strtolower($proceed) !== 'y') {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        if ($backup) {
            $this->createBackup($quiet);
        }

        $applied = [];
        foreach ($autoFixable as $fix) {
            if ($this->applyFix($fix, $quiet)) {
                $applied[] = $fix['fix'];
            }
        }

        if (!empty($applied) && !$quiet) {
            $this->line('');
            $this->success('✅ Applied fixes:');
            foreach ($applied as $fix) {
                $this->line("  • $fix");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Apply production template to current environment
     */
    private function applyProductionTemplate(bool $backup, bool $force, bool $quiet): int
    {
        if (!$quiet) {
            $this->info('📋 Applying production template...');
            $this->line('');
        }

        $templatePath = dirname(__DIR__, 3) . '/.env.production';
        $envPath = dirname(__DIR__, 3) . '/.env';

        if (!file_exists($templatePath)) {
            if (!$quiet) {
                $this->error('Production template (.env.production) not found.');
            }
            return Command::FAILURE;
        }

        if (!$force && !$quiet) {
            $this->warning('This will update your .env file with production-ready defaults.');
            $proceed = $this->prompt('Continue? (y/n)', 'n');
            if (strtolower($proceed) !== 'y') {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        if ($backup) {
            $this->createBackup($quiet);
        }

        // Apply template (reuse logic from InstallCommand)
        $templateContent = file_get_contents($templatePath);

        if (file_exists($envPath)) {
            $existingContent = file_get_contents($envPath);
            $templateContent = $this->mergeEnvConfigs($existingContent, $templateContent);
        }

        file_put_contents($envPath, $templateContent);

        if (!$quiet) {
            $this->success('✅ Production template applied successfully.');
            $this->line('');
            $this->warning('Remember to update:');
            $this->line('  • Generate secure keys: php glueful key:generate');
            $this->line('  • Set database credentials');
            $this->line('  • Configure CORS origins');
        }

        return Command::SUCCESS;
    }

    /**
     * Migrate configuration to specified environment
     */
    private function migrateEnvironment(string $targetEnv, bool $backup, bool $force, bool $quiet): int
    {
        $validEnvs = ['development', 'staging', 'production'];

        if (!in_array($targetEnv, $validEnvs)) {
            if (!$quiet) {
                $this->error("Invalid environment: $targetEnv");
                $this->line('Valid environments: ' . implode(', ', $validEnvs));
            }
            return Command::FAILURE;
        }

        if (!$quiet) {
            $this->info("🔄 Migrating configuration to: $targetEnv");
            $this->line('');
        }

        if ($backup) {
            $this->createBackup($quiet);
        }

        // Update APP_ENV
        $this->updateEnvValue('APP_ENV', $targetEnv);

        if (!$quiet) {
            $this->success("✅ Environment migrated to: $targetEnv");
            $this->line('');
            $this->info('The framework will automatically apply environment-specific defaults.');

            if ($targetEnv === 'production') {
                $this->warning('Run --check to validate production readiness.');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Generate comprehensive audit report
     */
    private function generateAuditReport(bool $quiet): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        $validation = SecurityManager::validateProductionEnvironment();
        $health = HealthService::getOverallHealth();
        $score = SecurityManager::getProductionReadinessScore();

        echo "=== GLUEFUL PRODUCTION CONFIGURATION AUDIT ===\n";
        echo "Generated: $timestamp\n";
        echo "Environment: {$validation['environment']}\n";
        echo "\n";

        echo "=== PRODUCTION READINESS SCORE ===\n";
        echo "Score: {$score['score']}/100 ({$score['status']})\n";
        echo "Message: {$score['message']}\n";
        echo "\n";

        if ($validation['is_production']) {
            echo "=== SECURITY VALIDATION ===\n";

            if (!empty($validation['warnings'])) {
                echo "Critical Issues (" . count($validation['warnings']) . "):\n";
                foreach ($validation['warnings'] as $i => $warning) {
                    echo "  " . ($i + 1) . ". $warning\n";
                }
                echo "\n";
            }

            if (!empty($validation['recommendations'])) {
                echo "Recommendations (" . count($validation['recommendations']) . "):\n";
                foreach ($validation['recommendations'] as $i => $rec) {
                    echo "  " . ($i + 1) . ". $rec\n";
                }
                echo "\n";
            }

            if (empty($validation['warnings']) && empty($validation['recommendations'])) {
                echo "No security issues detected.\n\n";
            }
        }

        echo "=== SYSTEM HEALTH ===\n";
        echo "Overall Status: {$health['status']}\n";
        foreach ($health['checks'] as $checkName => $check) {
            echo "$checkName: {$check['status']} - {$check['message']}\n";
        }
        echo "\n";

        echo "=== FIX SUGGESTIONS ===\n";
        $fixes = SecurityManager::getEnvironmentFixSuggestions();
        if (!empty($fixes)) {
            foreach ($fixes as $i => $fix) {
                echo ($i + 1) . ". {$fix['issue']}\n";
                echo "   Fix: {$fix['fix']}\n";
                echo "   Command: {$fix['command']}\n";
                echo "   Severity: {$fix['severity']}\n\n";
            }
        } else {
            echo "No fix suggestions available.\n";
        }

        echo "=== END OF AUDIT ===\n";
    }

    /**
     * Check if a fix can be automatically applied
     */
    private function canAutoFix(array $fix): bool
    {
        return in_array($fix['severity'], ['critical']) &&
               (str_contains($fix['command'], 'key:generate') ||
                str_contains($fix['command'], 'Set ') ||
                str_contains($fix['fix'], 'Generate'));
    }

    /**
     * Apply a specific fix
     */
    private function applyFix(array $fix, bool $quiet): bool
    {
        if (str_contains($fix['command'], 'key:generate --force')) {
            return $this->generateAppKey($quiet);
        }

        if (str_contains($fix['command'], 'key:generate --jwt')) {
            return $this->generateJwtKey($quiet);
        }

        if (str_contains($fix['command'], 'APP_DEBUG=false')) {
            return $this->updateEnvValue('APP_DEBUG', 'false');
        }

        if (str_contains($fix['command'], 'FORCE_HTTPS=true')) {
            return $this->updateEnvValue('FORCE_HTTPS', 'true');
        }

        if (str_contains($fix['command'], 'LOG_LEVEL=error')) {
            return $this->updateEnvValue('LOG_LEVEL', 'error');
        }

        return false;
    }

    /**
     * Generate secure APP_KEY
     */
    private function generateAppKey(bool $quiet): bool
    {
        $newKey = RandomStringGenerator::generate(32);
        $result = $this->updateEnvValue('APP_KEY', $newKey);

        if ($result && !$quiet) {
            $this->success('✓ Generated new APP_KEY');
        }

        return $result;
    }

    /**
     * Generate secure JWT_KEY
     */
    private function generateJwtKey(bool $quiet): bool
    {
        $newKey = RandomStringGenerator::generate(64);
        $result = $this->updateEnvValue('JWT_KEY', $newKey);

        if ($result && !$quiet) {
            $this->success('✓ Generated new JWT_KEY');
        }

        return $result;
    }

    /**
     * Create backup of current .env file
     */
    private function createBackup(bool $quiet): bool
    {
        $envPath = dirname(__DIR__, 3) . '/.env';

        if (!file_exists($envPath)) {
            return false;
        }

        $backupPath = $envPath . '.backup.' . date('Y-m-d-H-i-s');
        $result = copy($envPath, $backupPath);

        if ($result && !$quiet) {
            $this->success('✓ Backup created: ' . basename($backupPath));
        }

        return $result;
    }

    /**
     * Update environment value in .env file
     */
    private function updateEnvValue(string $key, string $value): bool
    {
        $envPath = dirname(__DIR__, 3) . '/.env';

        if (!file_exists($envPath)) {
            return false;
        }

        $content = file_get_contents($envPath);
        $pattern = "/^{$key}=.*/m";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            $content .= "\n{$key}={$value}";
        }

        return file_put_contents($envPath, $content) !== false;
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
            'GENERATE_SECURE_32_CHARACTER_KEY_HERE',
            'GENERATE_SECURE_JWT_SECRET_KEY_HERE',
        ];

        return in_array(trim($value, '"\''), $placeholders);
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

        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);

        return empty($input) ? $default : $input;
    }
}
