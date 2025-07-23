<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\System;

use Glueful\Security\SecurityManager;
use Glueful\Services\HealthService;
use Glueful\Security\RandomStringGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Production Configuration Command
 * - Comprehensive production readiness validation and scoring
 * - Advanced security audit and automated fix suggestions
 * - Environment migration assistance with rollback capabilities
 * - Production template management and configuration optimization
 * - Interactive configuration wizard and guided setup
 * - Compliance checking and security best practices enforcement
 * - Backup and restore functionality for configuration changes
 * @package Glueful\Console\Commands\System
 */
#[AsCommand(
    name: 'system:production',
    description: 'Comprehensive production environment configuration and validation'
)]
class ProductionCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Comprehensive production environment configuration and validation')
             ->setHelp('This command provides advanced production configuration management ' .
                      'including validation, security auditing, and automated optimization.')
             ->addOption(
                 'check',
                 'c',
                 InputOption::VALUE_NONE,
                 'Check production readiness and show issues'
             )
             ->addOption(
                 'score',
                 's',
                 InputOption::VALUE_NONE,
                 'Show production readiness score (0-100)'
             )
             ->addOption(
                 'fix',
                 'f',
                 InputOption::VALUE_NONE,
                 'Apply automatic fixes for detected issues'
             )
             ->addOption(
                 'template',
                 't',
                 InputOption::VALUE_NONE,
                 'Apply production template to current .env'
             )
             ->addOption(
                 'migrate',
                 'm',
                 InputOption::VALUE_REQUIRED,
                 'Migrate configuration to specified environment (development|staging|production)'
             )
             ->addOption(
                 'audit',
                 'a',
                 InputOption::VALUE_NONE,
                 'Generate comprehensive production audit report'
             )
             ->addOption(
                 'suggestions',
                 null,
                 InputOption::VALUE_NONE,
                 'Show detailed fix suggestions without applying'
             )
             ->addOption(
                 'backup',
                 'b',
                 InputOption::VALUE_NONE,
                 'Create backup before making changes (default: true)'
             )
             ->addOption(
                 'no-backup',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip backup creation'
             )
             ->addOption(
                 'force',
                 null,
                 InputOption::VALUE_NONE,
                 'Skip confirmations and apply changes directly'
             )
             ->addOption(
                 'interactive',
                 'i',
                 InputOption::VALUE_NONE,
                 'Run interactive configuration wizard'
             )
             ->addOption(
                 'dry-run',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show what would be changed without applying'
             )
             ->addOption(
                 'output-file',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Save audit report to file'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $this->io->title('🔧 Glueful Production Configuration Manager');

        // Handle interactive mode
        if ($input->getOption('interactive')) {
            return $this->runInteractiveMode($input);
        }

        // Default action if no specific option provided
        if (!$this->hasAnyOption($input)) {
            $input->setOption('check', true);
        }

        $exitCode = self::SUCCESS;

        // Execute requested actions in logical order
        if ($input->getOption('check')) {
            $exitCode = max($exitCode, $this->runProductionCheck($input));
        }

        if ($input->getOption('score')) {
            $this->showProductionScore($input);
        }

        if ($input->getOption('suggestions')) {
            $this->showFixSuggestions();
        }

        if ($input->getOption('fix')) {
            $exitCode = max($exitCode, $this->applyAutomaticFixes($input));
        }

        if ($input->getOption('template')) {
            $exitCode = max($exitCode, $this->applyProductionTemplate($input));
        }

        if ($migrate = $input->getOption('migrate')) {
            $exitCode = max($exitCode, $this->migrateEnvironment($migrate, $input));
        }

        if ($input->getOption('audit')) {
            $this->generateAuditReport($input);
        }

        return $exitCode;
    }

    private function hasAnyOption(InputInterface $input): bool
    {
        $options = ['check', 'score', 'fix', 'template', 'audit', 'suggestions', 'interactive'];
        foreach ($options as $option) {
            if ($input->getOption($option)) {
                return true;
            }
        }
        return $input->getOption('migrate') !== null;
    }

    private function runInteractiveMode(InputInterface $input): int
    {
        $this->io->section('🎯 Interactive Production Configuration Wizard');

        $this->io->text('This wizard will guide you through production configuration setup.');
        $this->io->newLine();

        // Step 1: Current status check
        $this->io->text('Step 1: Checking current configuration...');
        $validation = SecurityManager::validateProductionEnvironment();
        $health = HealthService::getOverallHealth();

        if (!empty($validation['warnings']) || $health['status'] !== 'ok') {
            $this->io->warning('Issues detected in current configuration.');

            if ($this->io->confirm('Would you like to see the issues?', true)) {
                $this->displayValidationResults($validation, $health);
            }

            if ($this->io->confirm('Apply automatic fixes?', true)) {
                $this->applyAutomaticFixes($input);
            }
        } else {
            $this->io->success('Current configuration looks good!');
        }

        // Step 2: Environment check
        if (!$validation['is_production']) {
            $this->io->text("Current environment: {$validation['environment']}");

            if ($this->io->confirm('Migrate to production environment?', false)) {
                $this->migrateEnvironment('production', $input);
            }
        }

        // Step 3: Security enhancements
        if ($this->io->confirm('Apply production security template?', true)) {
            $this->applyProductionTemplate($input);
        }

        // Step 4: Final validation
        $this->io->text('Running final validation...');
        $this->runProductionCheck($input);

        $this->io->success('Interactive configuration wizard completed!');
        return self::SUCCESS;
    }

    private function runProductionCheck(InputInterface $input): int
    {
        $this->io->section('🔍 Production Readiness Check');

        $validation = SecurityManager::validateProductionEnvironment();
        $health = HealthService::getOverallHealth();

        if (!$validation['is_production']) {
            $this->io->warning("⚠️ Current environment is not set to production");
            $this->io->text("Environment: {$validation['environment']}");
            $this->io->text('Set APP_ENV=production to enable production validation.');
            return self::SUCCESS;
        }

        $this->displayValidationResults($validation, $health);

        $hasIssues = !empty($validation['warnings']) || $health['status'] !== 'ok';

        if (!$hasIssues) {
            $this->io->success('✅ Production configuration is ready!');
            return self::SUCCESS;
        } else {
            $this->io->newLine();
            $this->io->text('💡 Use --fix to apply automatic fixes');
            $this->io->text('💡 Use --suggestions for detailed fix instructions');
            return self::FAILURE;
        }
    }

    private function displayValidationResults(array $validation, array $health): void
    {
        // Show critical issues
        if (!empty($validation['warnings'])) {
            $this->io->section('🚨 Critical Security Issues');
            foreach ($validation['warnings'] as $warning) {
                $this->io->text("❌ {$warning}");
            }
        }

        // Show recommendations
        if (!empty($validation['recommendations'])) {
            $this->io->section('💡 Security Recommendations');
            foreach ($validation['recommendations'] as $recommendation) {
                $this->io->text("• {$recommendation}");
            }
        }

        // Show health issues
        if ($health['status'] !== 'ok') {
            $this->io->section('🔧 System Health Issues');
            foreach ($health['checks'] as $checkName => $check) {
                if ($check['status'] !== 'ok') {
                    $this->io->text("❌ {$checkName}: {$check['message']}");
                }
            }
        }
    }

    private function showProductionScore(InputInterface $input): void
    {
        $this->io->section('📊 Production Readiness Score');

        $scoreData = SecurityManager::getProductionReadinessScore();

        $scoreColor = match (true) {
            $scoreData['score'] >= 90 => 'success',
            $scoreData['score'] >= 75 => 'info',
            $scoreData['score'] >= 60 => 'warning',
            default => 'error'
        };

        $this->io->text("<{$scoreColor}>Score: {$scoreData['score']}/100 ({$scoreData['status']})</{$scoreColor}>");
        $this->io->text($scoreData['message']);

        if (isset($scoreData['breakdown'])) {
            $this->io->newLine();
            $this->io->text('Breakdown:');
            $this->io->text("  • Critical Issues: {$scoreData['breakdown']['critical_issues']}");
            $this->io->text("  • Recommendations: {$scoreData['breakdown']['recommendations']}");
            $this->io->text("  • Total Checks: {$scoreData['breakdown']['total_checks']}");
        }
    }

    private function showFixSuggestions(): void
    {
        $this->io->section('🔧 Production Fix Suggestions');

        $fixes = SecurityManager::getEnvironmentFixSuggestions();

        if (empty($fixes)) {
            $this->io->success('No fix suggestions - configuration looks good!');
            return;
        }

        foreach ($fixes as $fix) {
            $severityColor = match ($fix['severity']) {
                'critical' => 'error',
                'warning' => 'warning',
                default => 'info'
            };

            $this->io->text("<{$severityColor}>[{$fix['severity']}]</{$severityColor}> {$fix['issue']}");
            $this->io->text("  Fix: {$fix['fix']}");
            $this->io->text("  Command: {$fix['command']}");
            $this->io->newLine();
        }
    }

    private function applyAutomaticFixes(InputInterface $input): int
    {
        $this->io->section('🔧 Applying Automatic Fixes');

        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $backup = $this->shouldCreateBackup($input);

        $fixes = SecurityManager::getEnvironmentFixSuggestions();
        $autoFixable = array_filter($fixes, fn($fix) => $this->canAutoFix($fix));

        if (empty($autoFixable)) {
            $this->io->warning('No automatic fixes available.');
            $this->io->text('Manual configuration required for detected issues.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be applied');
            $this->io->text('The following fixes would be applied:');
            foreach ($autoFixable as $fix) {
                $this->io->text("  • {$fix['fix']}");
            }
            return self::SUCCESS;
        }

        if (!$force) {
            $this->io->text('The following fixes will be applied:');
            foreach ($autoFixable as $fix) {
                $this->io->text("  • {$fix['fix']}");
            }
            $this->io->newLine();

            if (!$this->io->confirm('Proceed with automatic fixes?', true)) {
                $this->io->text('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        if ($backup) {
            $this->createBackup();
        }

        $applied = [];
        foreach ($autoFixable as $fix) {
            if ($this->applyFix($fix)) {
                $applied[] = $fix['fix'];
            }
        }

        if (!empty($applied)) {
            $this->io->newLine();
            $this->io->success('✅ Applied fixes:');
            foreach ($applied as $fix) {
                $this->io->text("  • {$fix}");
            }
        }

        return self::SUCCESS;
    }

    private function applyProductionTemplate(InputInterface $input): int
    {
        $this->io->section('📋 Applying Production Template');

        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $backup = $this->shouldCreateBackup($input);

        $templatePath = dirname(__DIR__, 5) . '/.env.production';
        $envPath = dirname(__DIR__, 5) . '/.env';

        if (!file_exists($templatePath)) {
            $this->io->error('Production template (.env.production) not found.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be applied');
            $this->io->text('Would apply production template to .env file');
            return self::SUCCESS;
        }

        if (!$force) {
            $this->io->warning('This will update your .env file with production-ready defaults.');
            if (!$this->io->confirm('Continue?', false)) {
                $this->io->text('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        if ($backup) {
            $this->createBackup();
        }

        // Apply template
        $templateContent = file_get_contents($templatePath);

        if (file_exists($envPath)) {
            $existingContent = file_get_contents($envPath);
            $templateContent = $this->mergeEnvConfigs($existingContent, $templateContent);
        }

        file_put_contents($envPath, $templateContent);

        $this->io->success('✅ Production template applied successfully.');
        $this->io->newLine();

        $this->io->text('🔑 Next steps:');
        $this->io->text('  • Generate secure keys: php glueful key:generate');
        $this->io->text('  • Set database credentials');
        $this->io->text('  • Configure CORS origins');
        $this->io->text('  • Run production check: --check');

        return self::SUCCESS;
    }

    private function migrateEnvironment(string $targetEnv, InputInterface $input): int
    {
        $validEnvs = ['development', 'staging', 'production'];

        if (!in_array($targetEnv, $validEnvs)) {
            $this->io->error("Invalid environment: {$targetEnv}");
            $this->io->text('Valid environments: ' . implode(', ', $validEnvs));
            return self::FAILURE;
        }

        $this->io->section("🔄 Migrating to Environment: {$targetEnv}");

        $dryRun = $input->getOption('dry-run');
        $backup = $this->shouldCreateBackup($input);

        if ($dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be applied');
            $this->io->text("Would migrate to environment: {$targetEnv}");
            return self::SUCCESS;
        }

        if ($backup) {
            $this->createBackup();
        }

        // Update APP_ENV
        $this->updateEnvValue('APP_ENV', $targetEnv);

        $this->io->success("✅ Environment migrated to: {$targetEnv}");
        $this->io->newLine();

        $this->io->text('ℹ️ The framework will automatically apply environment-specific defaults.');

        if ($targetEnv === 'production') {
            $this->io->text('💡 Run --check to validate production readiness.');
        }

        return self::SUCCESS;
    }

    private function generateAuditReport(InputInterface $input): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        $validation = SecurityManager::validateProductionEnvironment();
        $health = HealthService::getOverallHealth();
        $score = SecurityManager::getProductionReadinessScore();

        $report = $this->buildAuditReport($timestamp, $validation, $health, $score);

        $outputFile = $input->getOption('output-file');
        if ($outputFile) {
            file_put_contents($outputFile, $report);
            $this->io->success("Audit report saved to: {$outputFile}");
        } else {
            $this->io->section('📋 Production Configuration Audit Report');
            $this->io->text($report);
        }
    }

    private function buildAuditReport(string $timestamp, array $validation, array $health, array $score): string
    {
        $report = [];
        $report[] = "=== GLUEFUL PRODUCTION CONFIGURATION AUDIT ===";
        $report[] = "Generated: {$timestamp}";
        $report[] = "Environment: {$validation['environment']}";
        $report[] = "";

        $report[] = "=== PRODUCTION READINESS SCORE ===";
        $report[] = "Score: {$score['score']}/100 ({$score['status']})";
        $report[] = "Message: {$score['message']}";
        $report[] = "";

        if ($validation['is_production']) {
            $report[] = "=== SECURITY VALIDATION ===";

            if (!empty($validation['warnings'])) {
                $report[] = "Critical Issues (" . count($validation['warnings']) . "):";
                foreach ($validation['warnings'] as $i => $warning) {
                    $report[] = "  " . ($i + 1) . ". {$warning}";
                }
                $report[] = "";
            }

            if (!empty($validation['recommendations'])) {
                $report[] = "Recommendations (" . count($validation['recommendations']) . "):";
                foreach ($validation['recommendations'] as $i => $rec) {
                    $report[] = "  " . ($i + 1) . ". {$rec}";
                }
                $report[] = "";
            }

            if (empty($validation['warnings']) && empty($validation['recommendations'])) {
                $report[] = "No security issues detected.";
                $report[] = "";
            }
        }

        $report[] = "=== SYSTEM HEALTH ===";
        $report[] = "Overall Status: {$health['status']}";
        foreach ($health['checks'] as $checkName => $check) {
            $report[] = "{$checkName}: {$check['status']} - {$check['message']}";
        }
        $report[] = "";

        $report[] = "=== FIX SUGGESTIONS ===";
        $fixes = SecurityManager::getEnvironmentFixSuggestions();
        if (!empty($fixes)) {
            foreach ($fixes as $i => $fix) {
                $report[] = ($i + 1) . ". {$fix['issue']}";
                $report[] = "   Fix: {$fix['fix']}";
                $report[] = "   Command: {$fix['command']}";
                $report[] = "   Severity: {$fix['severity']}";
                $report[] = "";
            }
        } else {
            $report[] = "No fix suggestions available.";
        }

        $report[] = "=== END OF AUDIT ===";

        return implode("\n", $report);
    }

    private function shouldCreateBackup(InputInterface $input): bool
    {
        return $input->getOption('backup') || !$input->getOption('no-backup');
    }

    private function canAutoFix(array $fix): bool
    {
        return in_array($fix['severity'], ['critical']) &&
               (str_contains($fix['command'], 'key:generate') ||
                str_contains($fix['command'], 'Set ') ||
                str_contains($fix['fix'], 'Generate'));
    }

    private function applyFix(array $fix): bool
    {
        if (str_contains($fix['command'], 'key:generate --force')) {
            return $this->generateAppKey();
        }

        if (str_contains($fix['command'], 'key:generate --jwt')) {
            return $this->generateJwtKey();
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

    private function generateAppKey(): bool
    {
        $newKey = RandomStringGenerator::generate(32);
        $result = $this->updateEnvValue('APP_KEY', $newKey);

        if ($result) {
            $this->io->text('✓ Generated new APP_KEY');
        }

        return $result;
    }

    private function generateJwtKey(): bool
    {
        $newKey = RandomStringGenerator::generate(64);
        $result = $this->updateEnvValue('JWT_KEY', $newKey);

        if ($result) {
            $this->io->text('✓ Generated new JWT_KEY');
        }

        return $result;
    }

    private function createBackup(): bool
    {
        $envPath = dirname(__DIR__, 5) . '/.env';

        if (!file_exists($envPath)) {
            return false;
        }

        $backupPath = $envPath . '.backup.' . date('Y-m-d-H-i-s');
        $result = copy($envPath, $backupPath);

        if ($result) {
            $this->io->text('✓ Backup created: ' . basename($backupPath));
        }

        return $result;
    }

    private function updateEnvValue(string $key, string $value): bool
    {
        $envPath = dirname(__DIR__, 5) . '/.env';

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

                // Keep existing value if it's not a placeholder
                if (
                    isset($existingValues[$key]) &&
                    !$this->isPlaceholderValue($existingValues[$key])
                ) {
                    $mergedLines[] = "{$key}={$existingValues[$key]}";
                } else {
                    $mergedLines[] = $line;
                }
            } else {
                $mergedLines[] = $line;
            }
        }

        return implode("\n", $mergedLines);
    }

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
}
