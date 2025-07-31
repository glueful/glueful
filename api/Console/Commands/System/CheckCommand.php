<?php

namespace Glueful\Console\Commands\System;

use Glueful\Console\BaseCommand;
use Glueful\Services\HealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * System Check Command
 * - Comprehensive system health and configuration validation
 * - Production readiness assessment
 * - Automatic issue detection and fixing capabilities
 * - Detailed reporting with verbose output options
 * - Security and performance checks
 * @package Glueful\Console\Commands\System
 */
#[AsCommand(
    name: 'system:check',
    description: 'Validate framework installation and configuration'
)]
class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Validate framework installation and configuration')
             ->setHelp('This command validates the framework installation, checks system requirements, ' .
                      'and verifies configuration for optimal performance and security.')
             ->addOption(
                 'details',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show detailed information for each check'
             )
             ->addOption(
                 'fix',
                 'f',
                 InputOption::VALUE_NONE,
                 'Attempt to automatically fix common issues'
             )
             ->addOption(
                 'production',
                 'p',
                 InputOption::VALUE_NONE,
                 'Check production readiness and security'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('details');
        $fix = $input->getOption('fix');
        $production = $input->getOption('production');

        $this->info("🔍 Glueful Framework System Check");
        $this->line("");

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
            return self::SUCCESS;
        } else {
            $this->warning("⚠️  $passed/$total checks passed. Please address the issues above.");
            if (!$verbose) {
                $this->info("💡 Run with --details for more details");
            }
            return self::FAILURE;
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
        $baseDir = dirname(__DIR__, 5); // Adjust path for new location

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
        $envPath = dirname(__DIR__, 5) . '/.env'; // Adjust path for new location
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
        $baseDir = dirname(__DIR__, 5); // Adjust path for new location
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
        try {
            // Use ConnectionValidator to avoid duplicate queries with bootstrap validation
            $healthResult = \Glueful\Database\ConnectionValidator::performHealthCheck();
            $healthService = $this->getService(HealthService::class);
            return $healthService->convertToSystemCheckFormat($healthResult['details'] ?? $healthResult);
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Database check failed: ' . $e->getMessage(),
                'details' => [
                    'Ensure database configuration is correct in .env',
                    'Verify database server is running',
                    'Check database credentials'
                ]
            ];
        }
    }
}
