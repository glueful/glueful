<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\Commands\Security\BaseSecurityCommand;
use Glueful\Security\SecurityManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security Check Command
 * - Comprehensive security configuration validation
 * - Production environment security assessment
 * - Security score calculation and reporting
 * - Automatic fix suggestions and application
 * - Detailed verbose output and reporting
 * @package Glueful\Console\Commands\Security
 */
#[AsCommand(
    name: 'security:check',
    description: 'Check security configuration and show issues'
)]
class CheckCommand extends BaseSecurityCommand
{
    protected function configure(): void
    {
        $this->setDescription('Check security configuration and show issues')
             ->setHelp('This command performs comprehensive security checks and provides ' .
                      'recommendations for improving security configuration.')
             ->addOption(
                 'fix',
                 'f',
                 InputOption::VALUE_NONE,
                 'Attempt to automatically fix security issues'
             )
             ->addOption(
                 'verbose',
                 'v',
                 InputOption::VALUE_NONE,
                 'Show detailed information about each check'
             )
             ->addOption(
                 'production',
                 'p',
                 InputOption::VALUE_NONE,
                 'Check production-specific security requirements'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fix = $input->getOption('fix');
        $verbose = $input->getOption('verbose');
        $production = $input->getOption('production') || env('APP_ENV') === 'production';

        $this->info('🔒 Comprehensive Security Configuration Check');
        $this->line('');

        $overallStatus = true;
        $checks = [];

        // 1. Production Environment Validation
        $this->info('1. Production Environment Security...');
        $prodValidation = SecurityManager::validateProductionEnvironment();
        $checks['production'] = $this->processProductionCheck($prodValidation, $fix, $verbose);
        if (!$checks['production']['passed']) {
            $overallStatus = false;
        }

        // 2. Security Score Assessment
        $this->info('2. Security Readiness Score...');
        $scoreData = SecurityManager::getProductionReadinessScore();
        $checks['score'] = $this->processSecurityScore($scoreData, $verbose);
        if ($scoreData['score'] < 75) {
            $overallStatus = false;
        }

        // 3. System Health & Security
        $this->info('3. System Health & Security...');
        $checks['health'] = $this->processHealthChecks($fix, $verbose);
        if (!$checks['health']['passed']) {
            $overallStatus = false;
        }

        // 4. File Permissions Security
        $this->info('4. File Permissions Security...');
        $checks['permissions'] = $this->processPermissionChecks($fix, $verbose);
        if (!$checks['permissions']['passed']) {
            $overallStatus = false;
        }

        // 5. Configuration Security
        $this->info('5. Configuration Security...');
        $checks['config'] = $this->processConfigurationSecurity($production, $fix, $verbose);
        if (!$checks['config']['passed']) {
            $overallStatus = false;
        }

        // 6. Authentication & Session Security
        $this->info('6. Authentication & Session Security...');
        $checks['auth'] = $this->processAuthenticationSecurity($verbose);
        if (!$checks['auth']['passed']) {
            $overallStatus = false;
        }

        // 7. Network Security (CORS, Headers, Rate Limiting)
        $this->info('7. Network Security Configuration...');
        $checks['network'] = $this->processNetworkSecurity($verbose);
        if (!$checks['network']['passed']) {
            $overallStatus = false;
        }

        // Summary
        $this->line('');
        $this->info('📊 Security Check Summary');
        $this->line('═══════════════════════════════════════════');

        $passedCount = 0;
        $totalCount = count($checks);

        foreach ($checks as $category => $result) {
            $status = $result['passed'] ? '✅' : '❌';
            $this->line(sprintf('%-25s %s %s', ucfirst($category), $status, $result['message']));
            if ($result['passed']) {
                $passedCount++;
            }
        }

        $this->line('');

        if ($overallStatus) {
            $this->success("🎉 Security check passed! ({$passedCount}/{$totalCount} categories passed)");

            $this->info("Security Score: {$scoreData['score']}/100 ({$scoreData['status']})");
            $this->info($scoreData['message']);

            return self::SUCCESS;
        } else {
            $this->warning("⚠️ Security issues found ({$passedCount}/{$totalCount} categories passed)");

            // Show fix suggestions if not in fix mode
            if (!$fix) {
                $this->line('');
                $this->info('💡 To attempt automatic fixes, run:');
                $this->line('   php glueful security:check --fix');

                $fixes = SecurityManager::getEnvironmentFixSuggestions();
                if (!empty($fixes)) {
                    $this->line('');
                    $this->info('🔧 Available automatic fixes:');
                    foreach (array_slice($fixes, 0, 3) as $fix) {
                        $this->line("   • {$fix['fix']}");
                    }
                    if (count($fixes) > 3) {
                        $this->line("   ... and " . (count($fixes) - 3) . " more");
                    }
                }
            }

            return self::FAILURE;
        }
    }
}
