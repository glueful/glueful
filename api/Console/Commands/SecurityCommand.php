<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Security\SecurityManager;
use Glueful\Cache\CacheStore;
use Glueful\Helpers\{DatabaseConnectionTrait};
use Glueful\Exceptions\BusinessLogicException;

/**
 * Security Management Command
 *
 * Comprehensive security management system providing:
 * - Security configuration validation and fixes
 * - Vulnerability scanning and reporting
 * - Security audit generation
 * - Token management and revocation
 * - User security operations
 * - Emergency lockdown procedures
 * - PDF report generation with email delivery
 *
 * @package Glueful\Console\Commands
 */
class SecurityCommand extends Command
{
    use DatabaseConnectionTrait;

    /**
     * The name of the command
     */
    protected string $name = 'security';

    /**
     * The description of the command
     */
    protected string $description = 'Comprehensive security management and monitoring';

    /**
     * The command syntax
     */
    protected string $syntax = 'security [action] [options]';

    /**
     * Command options
     */
    protected array $options = [
        'check'                     => 'Check security configuration and show issues',
        'report'                    => 'Generate comprehensive security report',
        'scan'                      => 'Scan for security vulnerabilities',
        'check-vulnerabilities'     => 'Check for known vulnerabilities in dependencies',
        'lockdown'                  => 'Enable emergency security lockdown mode',
        'lockdown-status'           => 'Check current lockdown status',
        'lockdown-disable'          => 'Disable active lockdown',
        'audit'                     => 'Generate detailed security audit log',
        'reset-password'            => 'Force password reset for specific user',
        'revoke-tokens'             => 'Revoke all authentication tokens'
    ];

    /**
     * Service container for dependency injection
     */
    private ContainerInterface $container;

    /**
     * Constructor - uses dependency injection to prevent memory issues
     *
     * @param ContainerInterface|null $container Optional DI container
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? container();
    }

    /**
     * Get the command name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get Command Description
     *
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Execute the command
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    public function execute(array $args = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $action = $args[0];

        if (!array_key_exists($action, $this->options)) {
            $this->error("Unknown action: $action");
            $this->info($this->getHelp());
            return Command::FAILURE;
        }

        try {
            switch ($action) {
                case 'check':
                    return $this->handleSecurityCheck($args);

                case 'report':
                    return $this->handleSecurityReport($args);

                case 'scan':
                    return $this->handleSecurityScan($args);

                case 'check-vulnerabilities':
                    return $this->handleVulnerabilityCheck($args);

                case 'lockdown':
                    return $this->handleSecurityLockdown($args);

                case 'lockdown-status':
                    return $this->handleLockdownStatus($args);

                case 'lockdown-disable':
                    return $this->handleLockdownDisable($args);

                case 'audit':
                    return $this->handleSecurityAudit($args);

                case 'reset-password':
                    return $this->forcePasswordReset($args);

                case 'revoke-tokens':
                    return $this->revokeAllTokens();

                default:
                    $this->error("Action not implemented: $action");
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Security command failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Handle security configuration check
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleSecurityCheck(array $args): int
    {
        $fix = in_array('--fix', $args);
        $verbose = in_array('--verbose', $args);
        $production = in_array('--production', $args) || env('APP_ENV') === 'production';

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

            return Command::SUCCESS;
        } else {
            $this->warning("⚠️ Security issues found ({$passedCount}/{$totalCount} categories passed)");

            // Show fix suggestions if not in fix mode
            if (!$fix) {
                $this->line('');
                $this->info('💡 To attempt automatic fixes, run:');
                $this->line('   php glueful security check --fix');

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

            return Command::FAILURE;
        }
    }

    /**
     * Handle security report generation
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleSecurityReport(array $args): int
    {
        $format = $this->extractOptionValue($args, '--format', 'html');
        $email = $this->extractOptionValue($args, '--email');
        $output = $this->extractOptionValue($args, '--output');
        $includeVulns = in_array('--include-vulnerabilities', $args);
        $includeMetrics = in_array('--include-metrics', $args);
        $dateRange = $this->extractOptionValue($args, '--days', '30');

        $this->info("🔍 Generating comprehensive security report (format: $format)...");

        try {
            // 1. Gather security data
            $this->info('Gathering security data...');
            $reportData = $this->gatherSecurityReportData($dateRange, $includeVulns, $includeMetrics);

            // 2. Generate report based on format
            $this->info('Generating report content...');
            $report = $this->generateSecurityReport($reportData, $format);

            // 3. Save to file if output specified
            if ($output) {
                $this->info("Saving report to: $output");
                $this->saveReportToFile($report, $output, $format);
            }

            // 4. Send via email if specified
            if ($email) {
                $this->info("Sending report to: $email");
                $this->sendReportByEmail($report, $email, $format, $reportData['summary']);
            }

            // 5. Display summary
            $this->displayReportSummary($reportData['summary'], $format, $output, $email);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Security report generation failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Handle security vulnerability scan
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleSecurityScan(array $args): int
    {
        $this->info('Starting comprehensive security scan...');

        try {
            // Determine scan types from arguments
            $scanTypes = ['code', 'dependency', 'config'];
            if (in_array('--code-only', $args)) {
                $scanTypes = ['code'];
            } elseif (in_array('--dependencies-only', $args)) {
                $scanTypes = ['dependency'];
            } elseif (in_array('--config-only', $args)) {
                $scanTypes = ['config'];
            }

            // Run the vulnerability scan
            $scanner = $this->container->get(\Glueful\Security\VulnerabilityScanner::class);
            $results = $scanner->scan($scanTypes);

            // Display results
            $this->displayScanResults($results);

            // Determine exit code based on vulnerabilities found
            $critical = $results['summary']['critical'];
            $high = $results['summary']['high'];

            if ($critical > 0) {
                $this->error("Critical vulnerabilities found: {$critical}");
                return Command::FAILURE;
            } elseif ($high > 0) {
                $this->warning("High severity vulnerabilities found: {$high}");
                return Command::SUCCESS; // Still success, but with warning
            }

            $this->success('Security scan completed - no critical vulnerabilities found');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Security scan failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Handle vulnerability check in dependencies
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleVulnerabilityCheck(array $args): int
    {
        $this->info('Checking for known vulnerabilities in dependencies...');

        try {
            // Run dependency vulnerability check
            $scanner = $this->container->get(\Glueful\Security\VulnerabilityScanner::class);
            $results = $scanner->checkDependencyVulnerabilities();

            $vulnerabilityCount = count($results['vulnerabilities']);
            $packagesScanned = $results['scanned_packages'];

            $this->info("Scanned {$packagesScanned} packages");

            if ($vulnerabilityCount > 0) {
                $this->warning("Found {$vulnerabilityCount} vulnerable dependencies:");

                foreach ($results['vulnerabilities'] as $vulnerability) {
                    $this->error("  • {$vulnerability['package']} {$vulnerability['current_version']}");
                    $this->info("    {$vulnerability['description']}");
                    $this->info("    Recommendation: {$vulnerability['recommendation']}");
                    $this->info("");
                }

                return Command::FAILURE;
            }

            $this->success('No known vulnerabilities found in dependencies');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Vulnerability check failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Handle emergency security lockdown
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleSecurityLockdown(array $args): int
    {
        $reason = $this->extractOptionValue($args, '--reason', 'Emergency security lockdown');
        $duration = $this->extractOptionValue($args, '--duration', '1h');
        $severity = $this->extractOptionValue($args, '--severity', 'high');

        $this->warning("INITIATING EMERGENCY SECURITY LOCKDOWN");
        $this->info("Reason: $reason");
        $this->info("Severity: $severity");
        $this->info("Duration: $duration");

        try {
            $lockdownId = uniqid('lockdown_', true);
            $startTime = time();
            $endTime = $startTime + $this->parseDuration($duration);

            // Step 1: Revoke all active sessions/tokens
            $this->info('🔒 Step 1: Revoking all authentication tokens...');
            $tokenRevocationResult = $this->revokeAllTokens();

            if ($tokenRevocationResult !== Command::SUCCESS) {
                $this->warning('Token revocation completed with issues - continuing lockdown...');
            }

            // Step 2: Enable maintenance mode
            $this->info('🔧 Step 2: Enabling maintenance mode...');
            $this->enableMaintenanceMode($reason, $endTime);

            // Step 3: Disable non-essential API endpoints
            $this->info('🚫 Step 3: Disabling non-essential API endpoints...');
            $this->disableApiEndpoints($severity);

            // Step 4: Block suspicious IP addresses
            $this->info('🛡️ Step 4: Blocking suspicious IP addresses...');
            $blockedIps = $this->blockSuspiciousIPs($severity);

            // Step 5: Enable enhanced logging
            $this->info('📝 Step 5: Enabling enhanced security logging...');
            $this->enableEnhancedLogging($lockdownId);

            // Step 6: Send administrator alerts
            $this->info('📧 Step 6: Sending administrator alerts...');
            $this->sendAdministratorAlerts($reason, $severity, $lockdownId);

            // Step 7: Force password resets (if critical severity)
            if ($severity === 'critical') {
                $this->info('🔑 Step 7: Forcing password resets for all users...');
                $this->forceAllPasswordResets($lockdownId);
            }

            // Step 8: Disable user registrations
            $this->info('🚪 Step 8: Disabling new user registrations...');
            $this->disableUserRegistrations($endTime);

            // Step 9: Create lockdown record
            $this->info('📊 Step 9: Recording lockdown event...');
            $this->recordLockdownEvent($lockdownId, $reason, $severity, $startTime, $endTime, [
                'blocked_ips' => count($blockedIps),
                'tokens_revoked' => true,
                'maintenance_mode' => true,
                'registrations_disabled' => true,
                'enhanced_logging' => true,
                'password_resets_forced' => $severity === 'critical'
            ]);

            // Display summary
            $this->displayLockdownSummary($lockdownId, $reason, $severity, $blockedIps);

            $this->error('🚨 SECURITY LOCKDOWN ACTIVATED 🚨');
            $this->info("Lockdown ID: $lockdownId");
            $this->info('All authentication tokens have been revoked');
            $this->info('Users must re-authenticate to access the system');
            $this->info("Lockdown will auto-expire at: " . date('Y-m-d H:i:s', $endTime));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Security lockdown failed: {$e->getMessage()}");

            // Log the failure for investigation
            $this->logLockdownFailure($reason, $severity, $e);

            return Command::FAILURE;
        }
    }

    /**
     * Handle security audit log generation
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleSecurityAudit(array $args): int
    {
        $this->info('🔍 Generating Comprehensive Security Audit Report');
        $this->line('');

        // Parse command line options
        $options = $this->parseAuditOptions($args);

        try {
            // Get AuditLogger instance
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
            // Display audit parameters
            $this->displayAuditParameters($options);

            // Generate different types of reports based on options
            $reports = [];

            if ($options['all'] || $options['auth']) {
                $this->info('📊 Generating Authentication Report...');
                $reports['authentication'] = $this->generateAuthenticationReport($auditLogger, $options);
            }

            if ($options['all'] || $options['data']) {
                $this->info('📊 Generating Data Access Report...');
                $reports['data_access'] = $this->generateDataAccessReport($auditLogger, $options);
            }

            if ($options['all'] || $options['admin']) {
                $this->info('📊 Generating Administrative Actions Report...');
                $reports['admin'] = $this->generateAdminReport($auditLogger, $options);
            }

            if ($options['all'] || $options['config']) {
                $this->info('📊 Generating Configuration Changes Report...');
                $reports['configuration'] = $this->generateConfigurationReport($auditLogger, $options);
            }

            if ($options['all'] || $options['system']) {
                $this->info('📊 Generating System Events Report...');
                $reports['system'] = $this->generateSystemReport($auditLogger, $options);
            }

            // Display summary
            $this->displayAuditSummary($reports, $options);

            // Export to file if requested
            if ($options['export']) {
                $this->exportAuditReport($reports, $options);
            }

            $this->line('');
            $this->success('✅ Security audit completed successfully');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate security audit: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Revoke all authentication tokens
     *
     * @return int Exit code
     */
    protected function revokeAllTokens(): int
    {
        $this->info('🔒 Revoking all authentication tokens...');

        try {
            // Import required classes
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
            $queryBuilder = $this->getQueryBuilder();
            $cacheStore = $this->container->get(CacheStore::class);
            $tokenManager = \Glueful\Auth\TokenManager::class;

            // Initialize cache engine if not already initialized
            $tokenManager::initialize();

            // Step 1: Get all active sessions from database
            $this->info('📊 Retrieving all active sessions...');
            $activeSessions = $queryBuilder->select(
                'auth_sessions',
                ['access_token', 'refresh_token', 'user_uuid', 'uuid']
            )
                ->where(['status' => 'active'])
                ->get();

            $totalSessions = count($activeSessions);
            $this->info("Found {$totalSessions} active sessions to revoke");

            if ($totalSessions === 0) {
                $this->warning('No active sessions found to revoke');
                return Command::SUCCESS;
            }

            // Step 2: Revoke each session individually
            $revokedCount = 0;
            $failedCount = 0;

            $this->info('🔄 Revoking individual sessions...');

            foreach ($activeSessions as $session) {
                try {
                    // Revoke the session (this handles database update and audit logging)
                    $result = $tokenManager::revokeSession($session['access_token']);

                    if ($result > 0) {
                        // Remove token mapping from cache
                        $tokenManager::removeTokenMapping($session['access_token']);
                        $revokedCount++;
                    } else {
                        $failedCount++;
                        $this->warning("Failed to revoke session: {$session['uuid']}");
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->warning("Error revoking session {$session['uuid']}: {$e->getMessage()}");
                }
            }

            // Step 3: Clear all token mappings from cache (bulk operation)
            $this->info('🧹 Clearing token cache mappings...');
            try {
                // Clear all token-related cache entries
                // Note: This is a more aggressive approach to ensure no cached tokens remain
                $cacheStore->flush();
                $this->info('✅ Token cache cleared successfully');
            } catch (\Exception $e) {
                $this->warning("Cache clearing failed: {$e->getMessage()}");
            }

            // Step 4: Log the bulk revocation event
            $auditLogger->audit(
                'security',
                'bulk_token_revocation',
                \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                [
                    'total_sessions' => $totalSessions,
                    'revoked_count' => $revokedCount,
                    'failed_count' => $failedCount,
                    'initiated_by' => 'security_command',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                    'reason' => 'emergency_security_procedure'
                ]
            );

            // Step 5: Display results
            $this->line('');
            $this->info('📊 Token Revocation Summary');
            $this->line('══════════════════════════════');
            $this->info("Total sessions found: {$totalSessions}");
            $this->success("Successfully revoked: {$revokedCount}");

            if ($failedCount > 0) {
                $this->warning("Failed to revoke: {$failedCount}");
            }

            $this->line('');

            if ($revokedCount === $totalSessions) {
                $this->success('🎉 All authentication tokens have been successfully revoked');
                $this->info('All users will need to re-authenticate on their next request');
                return Command::SUCCESS;
            } else {
                $this->warning('⚠️ Some tokens could not be revoked - check logs for details');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Failed to revoke tokens: {$e->getMessage()}");

            // Log the failure
            try {
                $auditLogger->audit(
                    'security',
                    'bulk_token_revocation_failed',
                    \Glueful\Logging\AuditEvent::SEVERITY_ERROR,
                    [
                        'error_message' => $e->getMessage(),
                        'initiated_by' => 'security_command',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'CLI'
                    ]
                );
            } catch (\Exception $logError) {
                $this->warning("Additionally, failed to log the error: {$logError->getMessage()}");
            }

            return Command::FAILURE;
        }
    }

    /**
     * Force password reset for all users
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function forcePasswordReset(array $args): int
    {
        $email = $this->extractOptionValue($args, '--email');
        $username = $this->extractOptionValue($args, '--username');
        $uuid = $this->extractOptionValue($args, '--uuid');

        // Check if we have at least one identifier
        if (!$email && !$username && !$uuid) {
            $this->error('Please specify a user identifier:');
            $this->info('  --email=user@example.com');
            $this->info('  --username=johndoe');
            $this->info('  --uuid=user-uuid');
            return Command::FAILURE;
        }

        try {
            // Initialize UserRepository and PasswordHasher
            $userRepository = new \Glueful\Repository\UserRepository();
            $passwordHasher = new \Glueful\Auth\PasswordHasher();

            // Find the user
            $user = null;
            $identifier = null;
            $identifierType = null;

            if ($email) {
                $user = $userRepository->findByEmail($email);
                $identifier = $email;
                $identifierType = 'email';
            } elseif ($username) {
                $user = $userRepository->findByUsername($username);
                $identifier = $username;
                $identifierType = 'username';
            } elseif ($uuid) {
                $user = $userRepository->findByUuid($uuid);
                $identifier = $uuid;
                $identifierType = 'uuid';
            }

            if (!$user) {
                $this->error("User not found with $identifierType: $identifier");
                return Command::FAILURE;
            }

            // Check if user array contains validation errors
            if (isset($user['errors'])) {
                $this->error("Validation error: " . implode(', ', $user['errors']));
                return Command::FAILURE;
            }

            $this->info("Found user: {$user['username']} ({$user['email']})");

            // Generate a secure temporary password
            $temporaryPassword = \Glueful\Helpers\Utils::generateSecurePassword(16);

            // Hash the temporary password
            $hashedPassword = $passwordHasher->hash($temporaryPassword);

            // Update the user's password
            $success = $userRepository->setNewPassword(
                $user['email'], // Use email as identifier for consistency
                $hashedPassword,
                'email'
            );

            if ($success) {
                $this->success("Password reset successful for user: {$user['username']}");
                $this->line('');
                $this->warning("⚠️  IMPORTANT: Store this temporary password securely!");
                $this->info("Temporary Password: $temporaryPassword");
                $this->line('');
                $this->info("Security recommendations:");
                $this->info("1. Provide this password to the user through a secure channel");
                $this->info("2. Instruct the user to change their password immediately upon login");
                $this->info("3. Consider implementing password expiration for temporary passwords");
                $this->info("4. Log this action in your security audit trail");

                return Command::SUCCESS;
            } else {
                $this->error("Failed to reset password for user: {$user['username']}");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Password reset failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Extract option value from arguments
     *
     * @param array $args Arguments array
     * @param string $option Option name
     * @param mixed $default Default value
     * @return mixed Option value or default
     */
    private function extractOptionValue(array $args, string $option, $default = null)
    {
        foreach ($args as $arg) {
            if (strpos($arg, $option . '=') === 0) {
                return substr($arg, strlen($option) + 1);
            }
        }
        return $default;
    }

    /**
     * Display scan results in a formatted way
     *
     * @param array $results Scan results
     * @return void
     */
    private function displayScanResults(array $results): void
    {
        $this->info("Scan ID: {$results['scan_id']}");
        $this->info("Scan Types: " . implode(', ', $results['scan_types']));
        $this->info("");

        // Display summary
        $summary = $results['summary'];
        $total = $summary['total_vulnerabilities'];

        if ($total > 0) {
            $this->warning("Found {$total} vulnerabilities:");
            $this->info("  Critical: {$summary['critical']}");
            $this->info("  High: {$summary['high']}");
            $this->info("  Medium: {$summary['medium']}");
            $this->info("  Low: {$summary['low']}");
            $this->info("");

            // Display first 5 vulnerabilities
            $displayed = 0;
            foreach ($results['vulnerabilities'] as $vulnerability) {
                if ($displayed >= 5) {
                    $remaining = $total - $displayed;
                    $this->info("... and {$remaining} more vulnerabilities");
                    break;
                }

                $severity = strtoupper($vulnerability['severity']);
                $type = str_replace('_', ' ', $vulnerability['type']);
                $file = $vulnerability['file'] ?? 'N/A';
                $line = isset($vulnerability['line']) ? ":{$vulnerability['line']}" : '';

                $this->error("  [{$severity}] {$type}");
                $this->info("    File: {$file}{$line}");
                $this->info("    Description: {$vulnerability['description']}");
                $this->info("    Recommendation: {$vulnerability['recommendation']}");
                $this->info("");

                $displayed++;
            }
        } else {
            $this->success("No vulnerabilities found!");
        }
    }

    /**
     * Get Command Help
     *
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Security Management Command
==========================

Comprehensive security management and monitoring for the application.

Usage:
  security check [--fix]                          Check security configuration
  security report [--format=FORMAT] [--email=EMAIL] [--output=FILE] 
                   [--include-vulnerabilities] [--include-metrics] [--days=DAYS]  Generate security report
  security scan [--code-only|--dependencies-only|--config-only]  Scan for vulnerabilities
  security check-vulnerabilities                  Check dependency vulnerabilities
  security lockdown [--reason="REASON"] [--severity=LEVEL] [--duration=TIME]  Enable emergency lockdown
  security lockdown-status                        Check current lockdown status
  security lockdown-disable [--force] [--cleanup] Disable active lockdown
  security audit                                  Generate security audit log
  security reset-password [--email=EMAIL|--username=USERNAME|--uuid=UUID]  Force password reset
  security revoke-tokens                          Revoke all authentication tokens

Actions:
  check                   Validate security configuration and show issues
  report                  Generate comprehensive security report
  scan                    Perform security vulnerability scan
  check-vulnerabilities   Check for known vulnerabilities in dependencies
  lockdown                Enable emergency security lockdown mode
  lockdown-status         Check current lockdown status and details
  lockdown-disable        Disable active lockdown (with confirmation)
  audit                   Generate detailed security audit log
  reset-password          Force password reset for specific user
  revoke-tokens           Revoke all active authentication tokens and sessions

Options:
  --fix                   Apply automatic fixes for detected issues (check only)
  --format=FORMAT         Report format: html, json, csv, txt (default: html)
  --output=FILE           Save report to file
  --include-vulnerabilities Include vulnerability scan in report
  --include-metrics       Include detailed security metrics
  --days=DAYS             Number of days to analyze (default: 30)
  --email=EMAIL           Email address to send report to or target user for password reset
  --username=USERNAME     Username to target for password reset
  --uuid=UUID             User UUID to target for password reset
  --reason="REASON"       Reason for lockdown (lockdown only)
  --severity=LEVEL        Lockdown severity: low, medium, high, critical (default: high)
  --duration=TIME         Lockdown duration: 30m, 2h, 1d (default: 1h)
  --force                 Skip confirmation prompts (lockdown-disable only)
  --cleanup               Remove all lockdown files (lockdown-disable only)
  --code-only             Scan only code vulnerabilities (scan only)
  --dependencies-only     Scan only dependency vulnerabilities (scan only)
  --config-only           Scan only configuration issues (scan only)
  -h, --help              Show this help message

Examples:
  php glueful security check --fix
  php glueful security report --format=html --output=security_report.html
  php glueful security report --format=json --email=admin@company.com --include-vulnerabilities
  php glueful security report --format=csv --days=7 --include-metrics
  php glueful security scan
  php glueful security scan --code-only
  php glueful security check-vulnerabilities
  php glueful security lockdown --reason="security incident" --severity=high --duration=2h
  php glueful security lockdown --reason="DDoS attack" --severity=critical --duration=1d
  php glueful security lockdown-status
  php glueful security lockdown-disable --force
  php glueful security audit
  php glueful security reset-password --email=user@example.com
  php glueful security reset-password --username=johndoe
  php glueful security revoke-tokens

Security Features:
  - Configuration validation and automated fixes
  - Comprehensive vulnerability scanning
  - PDF report generation with email delivery
  - Emergency lockdown procedures
  - Token and session management
  - User security operations (password reset)
  - Detailed audit logging
  - Dependency vulnerability checking
  - Bulk token revocation for security incidents
HELP;
    }

    /**
     * Process production environment validation check
     */
    private function processProductionCheck(array $validation, bool $fix, bool $verbose): array
    {
        $passed = empty($validation['warnings']); // Only critical warnings affect pass/fail
        $criticalCount = count($validation['warnings']);

        if ($passed) {
            $message = $validation['is_production'] ?
                'Production environment secure' :
                'Development environment (no production checks needed)';
            $this->success("   ✅ $message");
        } else {
            $this->error("   ❌ $criticalCount critical production security issues found");

            if ($verbose || $fix) {
                foreach ($validation['warnings'] as $warning) {
                    $this->error("      • $warning");
                }
            }
        }

        if (!empty($validation['recommendations']) && $verbose) {
            $this->info('   💡 Recommendations:');
            foreach (array_slice($validation['recommendations'], 0, 3) as $rec) {
                $this->line("      • $rec");
            }
        }

        return ['passed' => $passed, 'message' => $message ?? "Critical issues: $criticalCount"];
    }

    /**
     * Process security score assessment
     */
    private function processSecurityScore(array $scoreData, bool $verbose): array
    {
        $score = $scoreData['score'];
        $status = $scoreData['status'];
        $passed = $score >= 75;

        if ($passed) {
            $this->success("   ✅ Security score: $score/100 ($status)");
        } else {
            $this->warning("   ⚠️ Security score: $score/100 ($status)");
        }

        if ($verbose && isset($scoreData['message'])) {
            $this->info("      {$scoreData['message']}");
        }

        return ['passed' => $passed, 'message' => "Score: $score/100 ($status)"];
    }

    /**
     * Process system health checks using SystemCheckCommand for consistency
     */
    private function processHealthChecks(bool $_fix, bool $verbose): array
    {
        // Use SystemCheckCommand's database check
        $systemChecker = $this->container->get(\Glueful\Console\Commands\SystemCheckCommand::class);
        $dbResult = $systemChecker->checkDatabase();
        // Use SystemCheckCommand's PHP extensions check
        $extensionsResult = $systemChecker->checkPhpExtensions();

        $issues = [];

        // Process database health
        if (!$dbResult['passed']) {
            $issues[] = "Database: {$dbResult['message']}";
            if ($verbose) {
                foreach ($dbResult['details'] as $detail) {
                    $issues[] = "  $detail";
                }
            }
        }

        // Process extensions health
        if (!$extensionsResult['passed']) {
            $issues[] = "Extensions: {$extensionsResult['message']}";
            if ($verbose) {
                foreach ($extensionsResult['details'] as $detail) {
                    $issues[] = "  $detail";
                }
            }
        }

        // Additional cache health check (not in SystemCheckCommand)
        try {
            $cacheHealth = \Glueful\Services\HealthService::checkCache();
            if ($cacheHealth['status'] !== 'ok') {
                $issues[] = "Cache: {$cacheHealth['message']}";
            }
        } catch (\Exception $e) {
            $issues[] = "Cache system failed: {$e->getMessage()}";
        }

        $passed = empty($issues);

        if ($passed) {
            $this->success('   ✅ All system components healthy');
        } else {
            $this->error("   ❌ " . count($issues) . " system health issues found");
            if ($verbose) {
                foreach ($issues as $issue) {
                    $this->error("      • $issue");
                }
            }
        }

        return ['passed' => $passed, 'message' => $passed ? 'System healthy' : count($issues) . ' issues'];
    }

    /**
     * Process file permission checks using SystemCheckCommand for consistency
     */
    private function processPermissionChecks(bool $fix, bool $verbose): array
    {
        // Use SystemCheckCommand's permission check with fix capability
        $systemChecker = $this->container->get(\Glueful\Console\Commands\SystemCheckCommand::class);
        $result = $systemChecker->checkPermissions($fix);

        $passed = $result['passed'];

        if ($passed) {
            $this->success('   ✅ File permissions secure');
        } else {
            $this->error("   ❌ {$result['message']}");
            if ($verbose && !empty($result['details'])) {
                foreach ($result['details'] as $detail) {
                    $this->error("      • $detail");
                }
            }
        }

        return [
            'passed' => $passed,
            'message' => $passed ? 'Permissions OK' : $result['message']
        ];
    }

    /**
     * Process configuration security checks using SystemCheckCommand as base
     */
    private function processConfigurationSecurity(bool $production, bool $_fix, bool $verbose): array
    {
        // Use SystemCheckCommand's configuration check
        $systemChecker = $this->container->get(\Glueful\Console\Commands\SystemCheckCommand::class);
        $systemConfigResult = $systemChecker->checkConfiguration($production);

        $issues = [];

        // Add system configuration issues
        if (!$systemConfigResult['passed']) {
            foreach ($systemConfigResult['details'] as $detail) {
                $issues[] = $detail;
            }
        }

        // Additional security-specific configuration checks
        $requiredEnvVars = [
            'APP_KEY' => 'Application encryption key not set',
            'JWT_SECRET' => 'JWT secret key not set',
            'DB_PASSWORD' => 'Database password not set'
        ];

        foreach ($requiredEnvVars as $var => $message) {
            $value = env($var);
            if (empty($value) || in_array($value, ['your-key-here', 'change-me', 'default'])) {
                $issues[] = $message;
            }
        }

        // Additional production-specific checks beyond SystemCheckCommand
        if ($production) {
            if (empty(env('APP_URL')) || env('APP_URL') === 'http://localhost') {
                $issues[] = 'APP_URL not configured for production';
            }
        }

        $passed = empty($issues);

        if ($passed) {
            $this->success('   ✅ Configuration secure');
        } else {
            $this->error("   ❌ " . count($issues) . " configuration issues found");
            if ($verbose) {
                foreach ($issues as $issue) {
                    $this->error("      • $issue");
                }
            }
        }

        return ['passed' => $passed, 'message' => $passed ? 'Config secure' : count($issues) . ' issues'];
    }

    /**
     * Process authentication and session security
     */
    private function processAuthenticationSecurity(bool $verbose): array
    {
        $issues = [];

        // Check JWT configuration
        $jwtSecret = env('JWT_SECRET');
        if (empty($jwtSecret) || strlen($jwtSecret) < 32) {
            $issues[] = 'JWT secret too short or missing';
        }

        // Check session configuration
        $sessionDriver = config('session.driver', 'file');
        if ($sessionDriver === 'file') {
            $sessionPath = config('session.path', sys_get_temp_dir());
            if (!is_writable($sessionPath)) {
                $issues[] = 'Session storage directory not writable';
            }
        }

        // Check password hashing
        $defaultHasher = config('auth.hasher', 'bcrypt');
        if (!in_array($defaultHasher, ['bcrypt', 'argon2i', 'argon2id'])) {
            $issues[] = 'Insecure password hashing algorithm';
        }

        $passed = empty($issues);

        if ($passed) {
            $this->success('   ✅ Authentication security configured');
        } else {
            $this->error("   ❌ " . count($issues) . " authentication issues found");
            if ($verbose) {
                foreach ($issues as $issue) {
                    $this->error("      • $issue");
                }
            }
        }

        return ['passed' => $passed, 'message' => $passed ? 'Auth secure' : count($issues) . ' issues'];
    }

    /**
     * Process network security checks
     */
    private function processNetworkSecurity(bool $verbose): array
    {
        $issues = [];

        // Check CORS configuration
        $corsOrigins = env('CORS_ALLOWED_ORIGINS', '*');
        if ($corsOrigins === '*' && env('APP_ENV') === 'production') {
            $issues[] = 'CORS allows all origins in production';
        }

        // Check HTTPS enforcement
        if (env('FORCE_HTTPS') !== true && env('APP_ENV') === 'production') {
            $issues[] = 'HTTPS not enforced in production';
        }

        // Check security headers
        $securityHeaders = [
            'HSTS_HEADER' => 'HSTS header not configured',
            'CSP_HEADER' => 'Content Security Policy not configured'
        ];

        foreach ($securityHeaders as $header => $message) {
            if (empty(env($header)) && env('APP_ENV') === 'production') {
                $issues[] = $message;
            }
        }

        // Check rate limiting
        $rateLimitEnabled = config('security.rate_limiter.defaults.ip.max_attempts', 0);
        if ($rateLimitEnabled <= 0) {
            $issues[] = 'Rate limiting not properly configured';
        }

        $passed = empty($issues);

        if ($passed) {
            $this->success('   ✅ Network security properly configured');
        } else {
            $this->warning("   ⚠️ " . count($issues) . " network security recommendations");
            if ($verbose) {
                foreach ($issues as $issue) {
                    $this->warning("      • $issue");
                }
            }
        }

        return ['passed' => $passed, 'message' => $passed ? 'Network secure' : count($issues) . ' recommendations'];
    }

    /**
     * Parse audit command options
     *
     * @param array $args Command arguments
     * @return array Parsed options
     */
    private function parseAuditOptions(array $args): array
    {
        $options = [
            'all' => true,
            'auth' => false,
            'data' => false,
            'admin' => false,
            'config' => false,
            'system' => false,
            'export' => false,
            'format' => 'console',
            'days' => 30,
            'start_date' => null,
            'end_date' => null,
            'output_file' => null
        ];

        // Check for specific report types
        if (in_array('--auth', $args)) {
            $options['all'] = false;
            $options['auth'] = true;
        }
        if (in_array('--data', $args)) {
            $options['all'] = false;
            $options['data'] = true;
        }
        if (in_array('--admin', $args)) {
            $options['all'] = false;
            $options['admin'] = true;
        }
        if (in_array('--config', $args)) {
            $options['all'] = false;
            $options['config'] = true;
        }
        if (in_array('--system', $args)) {
            $options['all'] = false;
            $options['system'] = true;
        }

        // Check for export options
        if (in_array('--export', $args)) {
            $options['export'] = true;
        }

        // Parse format option
        foreach ($args as $arg) {
            if (strpos($arg, '--format=') === 0) {
                $options['format'] = substr($arg, 9);
            }
            if (strpos($arg, '--days=') === 0) {
                $options['days'] = (int) substr($arg, 7);
            }
            if (strpos($arg, '--start-date=') === 0) {
                $options['start_date'] = substr($arg, 13);
            }
            if (strpos($arg, '--end-date=') === 0) {
                $options['end_date'] = substr($arg, 11);
            }
            if (strpos($arg, '--output=') === 0) {
                $options['output_file'] = substr($arg, 9);
                $options['export'] = true;
            }
        }

        // Set default date range if not specified
        if (!$options['start_date']) {
            $options['start_date'] = (new \DateTime())->modify('-' . $options['days'] . ' days')->format('Y-m-d');
        }
        if (!$options['end_date']) {
            $options['end_date'] = (new \DateTime())->format('Y-m-d');
        }
        if (!$options['end_date']) {
            $options['end_date'] = (new \DateTime())->format('Y-m-d');
        }

        return $options;
    }

    /**
     * Display audit parameters
     *
     * @param array $options Audit options
     * @return void
     */
    private function displayAuditParameters(array $options): void
    {
        $this->info('📋 Audit Parameters:');
        $this->line("   Date Range: {$options['start_date']} to {$options['end_date']}");
        $this->line("   Report Types: " . $this->getReportTypesString($options));
        $this->line("   Output Format: {$options['format']}");
        if ($options['export']) {
            $outputFile = $options['output_file'] ?? 'security_audit_' . date('Y-m-d_H-i-s') . '.json';
            $this->line("   Export File: {$outputFile}");
        }
        $this->line('');
    }

    /**
     * Get report types as string
     *
     * @param array $options Audit options
     * @return string
     */
    private function getReportTypesString(array $options): string
    {
        if ($options['all']) {
            return 'All';
        }

        $types = [];
        if ($options['auth']) {
            $types[] = 'Authentication';
        }
        if ($options['data']) {
            $types[] = 'Data Access';
        }
        if ($options['admin']) {
            $types[] = 'Administrative';
        }
        if ($options['config']) {
            $types[] = 'Configuration';
        }
        if ($options['system']) {
            $types[] = 'System';
        }

        return implode(', ', $types);
    }

    /**
     * Generate authentication report
     *
     * @param \Glueful\Logging\AuditLogger $auditLogger
     * @param array $options
     * @return array
     */
    private function generateAuthenticationReport($auditLogger, array $options): array
    {
        $report = $auditLogger->generateComplianceReport(
            'authentication',
            $options['start_date'],
            $options['end_date'],
            ['include_details' => false]
        );

        $summary = $report['summary'];

        $this->line("   Total Events: {$summary['total_events']}");
        $this->line("   Login Attempts: {$summary['login_attempts']}");
        $this->line("   Successful Logins: {$summary['successful_logins']}");
        $this->line("   Failed Logins: {$summary['failed_logins']}");
        $this->line("   Logouts: {$summary['logouts']}");
        $this->line("   Password Changes: {$summary['password_changes']}");
        $this->line("   MFA Events: {$summary['mfa_events']}");

        if (!empty($summary['by_ip'])) {
            $this->line("   Top IP Addresses:");
            $count = 0;
            foreach ($summary['by_ip'] as $ip => $requests) {
                if ($count >= 5) {
                    break;
                }
                $this->line("     $ip: $requests events");
                $count++;
            }
        }

        $this->line('');
        return $report;
    }

    /**
     * Generate data access report
     *
     * @param \Glueful\Logging\AuditLogger $auditLogger
     * @param array $options
     * @return array
     */
    private function generateDataAccessReport($auditLogger, array $options): array
    {
        $report = $auditLogger->generateComplianceReport(
            'data_access',
            $options['start_date'],
            $options['end_date'],
            ['include_details' => false]
        );

        $summary = $report['summary'];

        $this->line("   Total Events: {$summary['total_events']}");
        $this->line("   Read Operations: {$summary['reads']}");
        $this->line("   Create Operations: {$summary['creates']}");
        $this->line("   Update Operations: {$summary['updates']}");
        $this->line("   Delete Operations: {$summary['deletes']}");

        if (!empty($summary['by_resource_type'])) {
            $this->line("   Resource Types:");
            $count = 0;
            foreach ($summary['by_resource_type'] as $type => $operations) {
                if ($count >= 5) {
                    break;
                }
                $this->line("     $type: $operations operations");
                $count++;
            }
        }

        if (!empty($summary['by_severity'])) {
            $this->line("   By Severity:");
            foreach ($summary['by_severity'] as $severity => $count) {
                $this->line("     $severity: $count events");
            }
        }

        $this->line('');
        return $report;
    }

    /**
     * Generate admin report
     *
     * @param \Glueful\Logging\AuditLogger $auditLogger
     * @param array $options
     * @return array
     */
    private function generateAdminReport($auditLogger, array $options): array
    {
        $report = $auditLogger->generateComplianceReport(
            'admin',
            $options['start_date'],
            $options['end_date'],
            ['include_details' => false]
        );

        $summary = $report['summary'];

        $this->line("   Total Events: {$summary['total_events']}");

        if (!empty($summary['by_action'])) {
            $this->line("   Top Actions:");
            $count = 0;
            foreach ($summary['by_action'] as $action => $occurrences) {
                if ($count >= 5) {
                    break;
                }
                $this->line("     $action: $occurrences times");
                $count++;
            }
        }

        if (!empty($summary['by_admin'])) {
            $this->line("   Top Administrators:");
            $count = 0;
            foreach ($summary['by_admin'] as $admin => $actions) {
                if ($count >= 5) {
                    break;
                }
                $this->line("     $admin: $actions actions");
                $count++;
            }
        }

        $this->line('');
        return $report;
    }

    /**
     * Generate configuration report
     *
     * @param \Glueful\Logging\AuditLogger $auditLogger
     * @param array $options
     * @return array
     */
    private function generateConfigurationReport($auditLogger, array $options): array
    {
        $report = $auditLogger->generateComplianceReport(
            'configuration',
            $options['start_date'],
            $options['end_date'],
            ['include_details' => false]
        );

        $summary = $report['summary'];

        $this->line("   Total Events: {$summary['total_events']}");

        if (!empty($summary['by_action'])) {
            $this->line("   Configuration Changes:");
            foreach ($summary['by_action'] as $action => $count) {
                $this->line("     $action: $count times");
            }
        }

        $this->line('');
        return $report;
    }

    /**
     * Generate system report
     *
     * @param \Glueful\Logging\AuditLogger $auditLogger
     * @param array $options
     * @return array
     */
    private function generateSystemReport($auditLogger, array $options): array
    {
        $report = $auditLogger->generateComplianceReport(
            'system',
            $options['start_date'],
            $options['end_date'],
            ['include_details' => false]
        );

        $summary = $report['summary'];

        $this->line("   Total Events: {$summary['total_events']}");

        if (!empty($summary['by_action'])) {
            $this->line("   System Events:");
            $count = 0;
            foreach ($summary['by_action'] as $action => $occurrences) {
                if ($count >= 5) {
                    break;
                }
                $this->line("     $action: $occurrences times");
                $count++;
            }
        }

        if (!empty($summary['by_severity'])) {
            $this->line("   By Severity:");
            foreach ($summary['by_severity'] as $severity => $count) {
                $this->line("     $severity: $count events");
            }
        }

        $this->line('');
        return $report;
    }

    /**
     * Display audit summary
     *
     * @param array $reports Generated reports
     * @param array $options Audit options
     * @return void
     */
    private function displayAuditSummary(array $reports, array $options): void
    {
        $this->line('');
        $this->info('📊 Audit Summary');
        $this->line('═══════════════════════════════════════════');

        $totalEvents = 0;
        $criticalEvents = 0;
        $warningEvents = 0;

        foreach ($reports as $type => $report) {
            $summary = $report['summary'];
            $events = $summary['total_events'];
            $totalEvents += $events;

            // Count severity levels if available
            if (isset($summary['by_severity'])) {
                $criticalEvents += $summary['by_severity']['critical'] ?? 0;
                $criticalEvents += $summary['by_severity']['error'] ?? 0;
                $warningEvents += $summary['by_severity']['warning'] ?? 0;
            }

            $this->line(sprintf('%-20s: %s events', ucfirst(str_replace('_', ' ', $type)), $events));
        }

        $this->line('');
        $this->line("Total Events: $totalEvents");
        if ($criticalEvents > 0) {
            $this->error("Critical/Error Events: $criticalEvents");
        }
        if ($warningEvents > 0) {
            $this->warning("Warning Events: $warningEvents");
        }

        // Security recommendations
        $this->line('');
        $this->info('🔐 Security Recommendations:');

        if ($criticalEvents > 0) {
            $this->warning('• Review critical events immediately');
        }

        // Check for suspicious patterns
        foreach ($reports as $type => $report) {
            if ($type === 'authentication') {
                $summary = $report['summary'];
                $failureRate = $summary['failed_logins'] / max($summary['login_attempts'], 1);
                if ($failureRate > 0.3) {
                    $this->warning('• High authentication failure rate detected');
                }

                if ($summary['password_changes'] > 100) {
                    $this->warning('• Unusual number of password changes');
                }
            }
        }

        if ($totalEvents < 10) {
            $this->info('• Low audit activity - consider enabling more logging');
        }
    }

    /**
     * Export audit report to file
     *
     * @param array $reports Generated reports
     * @param array $options Audit options
     * @return void
     */
    private function exportAuditReport(array $reports, array $options): void
    {
        $outputFile = $options['output_file'] ?? 'security_audit_' . date('Y-m-d_H-i-s') . '.json';

        $exportData = [
            'generated_at' => (new \DateTime())->format('c'),
            'date_range' => [
                'start' => $options['start_date'],
                'end' => $options['end_date']
            ],
            'report_types' => $this->getReportTypesString($options),
            'reports' => $reports,
            'metadata' => [
                'generator' => 'Glueful Security Command',
                'version' => '1.0.0',
                'format' => $options['format']
            ]
        ];

        try {
            $content = '';

            switch ($options['format']) {
                case 'json':
                    $content = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    break;

                case 'csv':
                    $content = $this->convertReportsToCsv($reports);
                    break;

                default:
                    $content = $this->convertReportsToText($reports, $options);
                    break;
            }

            file_put_contents($outputFile, $content);
            $this->success("✅ Audit report exported to: $outputFile");
        } catch (\Exception $e) {
            $this->error("Failed to export audit report: {$e->getMessage()}");
        }
    }

    /**
     * Convert reports to CSV format
     *
     * @param array $reports
     * @return string
     */
    private function convertReportsToCsv(array $reports): string
    {
        $csv = "Report Type,Total Events,Generated At\n";

        foreach ($reports as $type => $report) {
            $csv .= sprintf(
                "%s,%d,%s\n",
                ucfirst(str_replace('_', ' ', $type)),
                $report['summary']['total_events'],
                $report['generated_at']
            );
        }

        return $csv;
    }

    /**
     * Convert reports to text format
     *
     * @param array $reports
     * @param array $options
     * @return string
     */
    private function convertReportsToText(array $reports, array $options): string
    {
        $text = "SECURITY AUDIT REPORT\n";
        $text .= "=====================\n\n";
        $text .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $text .= "Date Range: {$options['start_date']} to {$options['end_date']}\n";
        $text .= "Report Types: " . $this->getReportTypesString($options) . "\n\n";

        foreach ($reports as $type => $report) {
            $text .= strtoupper(str_replace('_', ' ', $type)) . " REPORT\n";
            $text .= str_repeat('-', strlen($type) + 7) . "\n";
            $text .= "Total Events: " . $report['summary']['total_events'] . "\n";

            if ($type === 'authentication') {
                $summary = $report['summary'];
                $text .= "Login Attempts: {$summary['login_attempts']}\n";
                $text .= "Successful Logins: {$summary['successful_logins']}\n";
                $text .= "Failed Logins: {$summary['failed_logins']}\n";
            }

            $text .= "\n";
        }

        return $text;
    }

    /**
     * Gather comprehensive security data for report
     *
     * @param string $dateRange Number of days to analyze
     * @param bool $includeVulns Include vulnerability scan
     * @param bool $includeMetrics Include detailed metrics
     * @return array
     */
    private function gatherSecurityReportData(string $dateRange, bool $includeVulns, bool $includeMetrics): array
    {
        $days = (int) $dateRange;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');

        $data = [
            'metadata' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'report_period' => "{$startDate} to {$endDate}",
                'server' => gethostname(),
                'environment' => env('APP_ENV', 'unknown'),
                'days_analyzed' => $days
            ],
            'security_config' => $this->analyzeSecurityConfiguration(),
            'system_health' => $this->analyzeSystemSecurity(),
            'authentication' => $this->analyzeAuthenticationSecurity($days),
            'access_control' => $this->analyzeAccessControl($days),
            'audit_summary' => $this->getAuditSummary($days),
            'compliance' => $this->assessCompliance(),
            'recommendations' => []
        ];

        if ($includeVulns) {
            $this->info('Running vulnerability assessment...');
            $data['vulnerabilities'] = $this->runVulnerabilityAssessment();
        }

        if ($includeMetrics) {
            $this->info('Gathering security metrics...');
            $data['metrics'] = $this->gatherSecurityMetrics($days);
        }

        // Generate recommendations based on findings
        $data['recommendations'] = $this->generateSecurityRecommendations($data);

        // Create summary
        $data['summary'] = $this->createReportSummary($data);

        return $data;
    }

    /**
     * Analyze security configuration
     *
     * @return array
     */
    private function analyzeSecurityConfiguration(): array
    {
        // Use existing SecurityManager validation
        $prodValidation = SecurityManager::validateProductionEnvironment();
        $scoreData = SecurityManager::getProductionReadinessScore();

        return [
            'production_readiness' => [
                'score' => $scoreData['score'],
                'status' => $scoreData['status'],
                'warnings' => $prodValidation['warnings'],
                'recommendations' => $prodValidation['recommendations']
            ],
            'environment_security' => [
                'debug_mode' => env('APP_DEBUG', false),
                'environment' => env('APP_ENV', 'unknown'),
                'https_enforced' => env('FORCE_HTTPS', false),
                'session_secure' => config('session.secure', false),
                'app_key_set' => !empty(env('APP_KEY')),
                'jwt_secret_set' => !empty(env('JWT_SECRET'))
            ],
            'key_security' => [
                'app_key_strength' => $this->assessKeyStrength(env('APP_KEY')),
                'jwt_key_strength' => $this->assessKeyStrength(env('JWT_SECRET')),
                'encryption_algorithm' => config('app.cipher', 'unknown')
            ]
        ];
    }

    /**
     * Analyze system security health
     *
     * @return array
     */
    private function analyzeSystemSecurity(): array
    {
        try {
            $systemChecker = $this->container->get(\Glueful\Console\Commands\SystemCheckCommand::class);

            return [
                'database' => $systemChecker->checkDatabase(),
                'php_extensions' => $systemChecker->checkPhpExtensions(),
                'permissions' => $systemChecker->checkPermissions(false),
                'cache_health' => $this->checkCacheHealth(),
                'storage_security' => $this->checkStorageSecurity()
            ];
        } catch (\Exception $e) {
            return ['error' => 'System security analysis failed: ' . $e->getMessage()];
        }
    }

    /**
     * Analyze authentication security
     *
     * @param int $days Days to analyze
     * @return array
     */
    private function analyzeAuthenticationSecurity(int $days): array
    {
        return [
            'login_metrics' => $this->getAuthenticationMetrics($days),
            'failed_attempts' => $this->getFailedAuthenticationAttempts($days),
            'password_policy' => $this->analyzePasswordPolicy(),
            'session_security' => $this->analyzeSessionSecurity(),
            'token_management' => $this->analyzeTokenManagement(),
            'suspicious_activity' => $this->getSuspiciousAuthActivity($days)
        ];
    }

    /**
     * Analyze access control
     *
     * @param int $days Days to analyze
     * @return array
     */
    private function analyzeAccessControl(int $days): array
    {
        try {
            return [
                'permission_system' => $this->analyzePermissionSystem(),
                'role_management' => $this->analyzeRoleManagement(),
                'api_access' => $this->analyzeApiAccess($days),
                'admin_access' => $this->analyzeAdminAccess($days),
                'privilege_escalation' => $this->checkPrivilegeEscalation($days)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Access control analysis failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get audit summary
     *
     * @param int $days Days to analyze
     * @return array
     */
    private function getAuditSummary(int $days): array
    {
        try {
            return [
                'total_events' => $this->getAuditEventCount($days),
                'security_events' => $this->getSecurityEventCount($days),
                'admin_events' => $this->getAdminEventCount($days),
                'error_events' => $this->getErrorEventCount($days),
                'event_types' => $this->getEventTypeBreakdown($days)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Audit summary failed: ' . $e->getMessage()];
        }
    }

    /**
     * Assess compliance status
     *
     * @return array
     */
    private function assessCompliance(): array
    {
        return [
            'owasp_top10' => $this->assessOWASPCompliance(),
            'gdpr' => $this->assessGDPRCompliance(),
            'security_headers' => $this->assessSecurityHeaders(),
            'data_protection' => $this->assessDataProtection(),
            'logging_monitoring' => $this->assessLoggingMonitoring()
        ];
    }

    /**
     * Run vulnerability assessment
     *
     * @return array
     */
    private function runVulnerabilityAssessment(): array
    {
        try {
            $scanner = $this->container->get(\Glueful\Security\VulnerabilityScanner::class);
            $results = $scanner->scan(['code', 'dependency', 'config']);

            return [
                'scan_id' => $results['scan_id'],
                'timestamp' => $results['timestamp'],
                'summary' => $results['summary'],
                'critical_vulnerabilities' => array_filter(
                    $results['vulnerabilities'],
                    fn($v) => $v['severity'] === 'critical'
                ),
                'high_vulnerabilities' => array_filter(
                    $results['vulnerabilities'],
                    fn($v) => $v['severity'] === 'high'
                ),
                'recommendations' => $this->generateVulnerabilityRecommendations($results)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Vulnerability scan failed: ' . $e->getMessage()];
        }
    }

    /**
     * Gather security metrics
     *
     * @param int $days Days to analyze
     * @return array
     */
    private function gatherSecurityMetrics(int $days): array
    {
        return [
            'response_times' => $this->getSecurityResponseTimes($days),
            'threat_detection' => $this->getThreatDetectionMetrics($days),
            'incident_response' => $this->getIncidentResponseMetrics($days),
            'security_training' => $this->getSecurityTrainingMetrics($days),
            'patch_management' => $this->getPatchManagementMetrics($days)
        ];
    }

    /**
     * Generate security recommendations
     *
     * @param array $data Analysis data
     * @return array
     */
    private function generateSecurityRecommendations(array $data): array
    {
        $recommendations = [];

        // Check production readiness score
        $score = $data['security_config']['production_readiness']['score'];
        if ($score < 75) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'configuration',
                'title' => 'Improve Production Security Configuration',
                'description' => "Current security score is {$score}/100. Address configuration warnings to " .
                               "improve security posture.",
                'action' => 'Run: php glueful security check --fix'
            ];
        }

        // Check for vulnerabilities
        if (isset($data['vulnerabilities']) && $data['vulnerabilities']['summary']['critical'] > 0) {
            $critical = $data['vulnerabilities']['summary']['critical'];
            $recommendations[] = [
                'priority' => 'critical',
                'category' => 'vulnerabilities',
                'title' => 'Address Critical Vulnerabilities',
                'description' => "{$critical} critical vulnerabilities found that require immediate attention.",
                'action' => 'Review vulnerability scan results and apply patches'
            ];
        }

        // Check authentication issues
        if (
            isset($data['authentication']['failed_attempts']['count']) &&
            $data['authentication']['failed_attempts']['count'] > 100
        ) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'authentication',
                'title' => 'High Number of Failed Login Attempts',
                'description' => 'Consider implementing additional rate limiting or monitoring.',
                'action' => 'Review authentication logs and consider implementing CAPTCHA or account lockout'
            ];
        }

        // Add more recommendation logic based on other findings
        $recommendations = array_merge($recommendations, $this->generateComplianceRecommendations($data));

        return $recommendations;
    }

    /**
     * Create report summary
     *
     * @param array $data Analysis data
     * @return array
     */
    private function createReportSummary(array $data): array
    {
        $score = $data['security_config']['production_readiness']['score'];
        $criticalIssues = 0;
        $highIssues = 0;
        $mediumIssues = 0;

        // Count issues from vulnerabilities
        if (isset($data['vulnerabilities']['summary'])) {
            $criticalIssues += $data['vulnerabilities']['summary']['critical'];
            $highIssues += $data['vulnerabilities']['summary']['high'];
            $mediumIssues += $data['vulnerabilities']['summary']['medium'];
        }

        // Count configuration warnings
        $criticalIssues += count($data['security_config']['production_readiness']['warnings']);

        $status = match (true) {
            $criticalIssues > 0 => 'Critical Issues Found',
            $highIssues > 5 => 'High Risk',
            $score >= 85 => 'Good Security Posture',
            $score >= 70 => 'Moderate Security',
            default => 'Needs Improvement'
        };

        return [
            'overall_score' => $score,
            'security_status' => $status,
            'critical_issues' => $criticalIssues,
            'high_issues' => $highIssues,
            'medium_issues' => $mediumIssues,
            'total_recommendations' => count($data['recommendations']),
            'environment' => $data['metadata']['environment'],
            'report_date' => $data['metadata']['generated_at']
        ];
    }

    /**
     * Generate security report in specified format
     *
     * @param array $data Report data
     * @param string $format Output format
     * @return string Generated report
     */
    private function generateSecurityReport(array $data, string $format): string
    {
        return match ($format) {
            'html' => $this->generateHtmlReport($data),
            'json' => $this->generateJsonReport($data),
            'csv' => $this->generateCsvReport($data),
            'txt' => $this->generateTextReport($data),
            default => throw BusinessLogicException::operationNotAllowed(
                'report_generation',
                "Unsupported format: $format"
            )
        };
    }

    /**
     * Generate HTML report
     *
     * @param array $data Report data
     * @return string HTML content
     */
    private function generateHtmlReport(array $data): string
    {
        $html = $this->getHtmlTemplate();

        $replacements = [
            '{{TITLE}}' => 'Security Assessment Report',
            '{{GENERATED_AT}}' => $data['metadata']['generated_at'],
            '{{REPORT_PERIOD}}' => $data['metadata']['report_period'],
            '{{SERVER}}' => $data['metadata']['server'],
            '{{ENVIRONMENT}}' => $data['metadata']['environment'],
            '{{OVERALL_SCORE}}' => $data['summary']['overall_score'],
            '{{SECURITY_STATUS}}' => $data['summary']['security_status'],
            '{{SECURITY_STATUS_CLASS}}' => $this->getStatusClass($data['summary']['overall_score']),
            '{{CRITICAL_ISSUES}}' => $data['summary']['critical_issues'],
            '{{HIGH_ISSUES}}' => $data['summary']['high_issues'],
            '{{MEDIUM_ISSUES}}' => $data['summary']['medium_issues'],
            '{{EXECUTIVE_SUMMARY}}' => $this->generateExecutiveSummary($data),
            '{{DETAILED_FINDINGS}}' => $this->generateDetailedFindings($data),
            '{{RECOMMENDATIONS}}' => $this->formatRecommendationsHtml($data['recommendations'])
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Generate JSON report
     *
     * @param array $data Report data
     * @return string JSON content
     */
    private function generateJsonReport(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Generate CSV report
     *
     * @param array $data Report data
     * @return string CSV content
     */
    private function generateCsvReport(array $data): string
    {
        $csv = "Section,Metric,Value,Status\n";

        $csv .= "Summary,Overall Score,{$data['summary']['overall_score']}," .
                $this->getStatusForScore($data['summary']['overall_score']) . "\n";
        $csv .= "Summary,Critical Issues,{$data['summary']['critical_issues']}," .
                ($data['summary']['critical_issues'] > 0 ? 'CRITICAL' : 'OK') . "\n";
        $csv .= "Summary,High Issues,{$data['summary']['high_issues']}," .
                ($data['summary']['high_issues'] > 0 ? 'HIGH' : 'OK') . "\n";

        $score = $data['security_config']['production_readiness']['score'];
        $csv .= "Configuration,Production Readiness,$score," . $this->getStatusForScore($score) . "\n";

        if (isset($data['vulnerabilities']['summary'])) {
            $vuln = $data['vulnerabilities']['summary'];
            $csv .= "Vulnerabilities,Total,{$vuln['total_vulnerabilities']}," .
                    ($vuln['total_vulnerabilities'] > 0 ? 'FOUND' : 'NONE') . "\n";
            $csv .= "Vulnerabilities,Critical,{$vuln['critical']}," .
                    ($vuln['critical'] > 0 ? 'CRITICAL' : 'OK') . "\n";
        }

        foreach ($data['recommendations'] as $i => $rec) {
            $csv .= "Recommendations," . ($i + 1) . ",\"" .
                    str_replace('"', '""', $rec['title']) . "\",{$rec['priority']}\n";
        }

        return $csv;
    }

    /**
     * Generate text report
     *
     * @param array $data Report data
     * @return string Text content
     */
    private function generateTextReport(array $data): string
    {
        $text = "SECURITY ASSESSMENT REPORT\n";
        $text .= "=========================\n\n";
        $text .= "Generated: {$data['metadata']['generated_at']}\n";
        $text .= "Period: {$data['metadata']['report_period']}\n";
        $text .= "Server: {$data['metadata']['server']}\n";
        $text .= "Environment: {$data['metadata']['environment']}\n\n";

        $text .= "EXECUTIVE SUMMARY\n";
        $text .= "-----------------\n";
        $text .= "Overall Security Score: {$data['summary']['overall_score']}/100 " .
                 "({$data['summary']['security_status']})\n";
        $text .= "Critical Issues: {$data['summary']['critical_issues']}\n";
        $text .= "High Issues: {$data['summary']['high_issues']}\n";
        $text .= "Medium Issues: {$data['summary']['medium_issues']}\n\n";

        $text .= "SECURITY CONFIGURATION\n";
        $text .= "----------------------\n";
        $score = $data['security_config']['production_readiness']['score'];
        $text .= "Production Readiness: $score/100\n";
        $text .= "Environment: {$data['security_config']['environment_security']['environment']}\n";
        $debugMode = $data['security_config']['environment_security']['debug_mode'] ? 'ENABLED' : 'DISABLED';
        $text .= "Debug Mode: $debugMode\n";
        $httpsEnforced = $data['security_config']['environment_security']['https_enforced'] ? 'YES' : 'NO';
        $text .= "HTTPS Enforced: $httpsEnforced\n\n";

        if (isset($data['vulnerabilities'])) {
            $text .= "VULNERABILITY SCAN\n";
            $text .= "-----------------\n";
            $vuln = $data['vulnerabilities']['summary'];
            $text .= "Total Vulnerabilities: {$vuln['total_vulnerabilities']}\n";
            $text .= "Critical: {$vuln['critical']}\n";
            $text .= "High: {$vuln['high']}\n";
            $text .= "Medium: {$vuln['medium']}\n";
            $text .= "Low: {$vuln['low']}\n\n";
        }

        $text .= "RECOMMENDATIONS\n";
        $text .= "---------------\n";
        foreach ($data['recommendations'] as $i => $rec) {
            $text .= ($i + 1) . ". [{$rec['priority']}] {$rec['title']}\n";
            $text .= "   {$rec['description']}\n";
            $text .= "   Action: {$rec['action']}\n\n";
        }

        return $text;
    }

    /**
     * Save report to file
     *
     * @param string $report Report content
     * @param string $output Output path
     * @param string $format Report format
     * @return void
     */
    private function saveReportToFile(string $report, string $output, string $format): void
    {
        $reportsDir = dirname($output);
        if (!is_dir($reportsDir) && $reportsDir !== '.') {
            mkdir($reportsDir, 0755, true);
        }

        file_put_contents($output, $report);
        $this->success("Report saved to: $output");
    }

    /**
     * Send report by email
     *
     * @param string $report Report content
     * @param string $email Email address
     * @param string $format Report format
     * @param array $summary Report summary
     * @return void
     */
    private function sendReportByEmail(string $report, string $email, string $format, array $summary): void
    {
        $subject = "Security Assessment Report - " . date('Y-m-d');
        $attachmentName = "security_report_" . date('Y-m-d_H-i-s') . ".$format";

        $emailBody = $this->createEmailBody($summary);

        $reportsDir = config('app.paths.storage', './storage/') . 'reports/';
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }

        $emailFile = $reportsDir . "email_" . $attachmentName;
        file_put_contents($emailFile, $report);

        error_log("Security report would be sent to: $email with subject: $subject");
        error_log("Email body: $emailBody");
        error_log("Attachment: $emailFile");

        $this->success("Email prepared for: $email");
        $this->info("Report saved for email delivery: $emailFile");
    }

    /**
     * Display report summary
     *
     * @param array $summary Report summary
     * @param string $format Report format
     * @param string|null $output Output file
     * @param string|null $email Email address
     * @return void
     */
    private function displayReportSummary(array $summary, string $format, ?string $output, ?string $email): void
    {
        $this->line('');
        $this->info('📊 Security Report Summary');
        $this->line('═══════════════════════════════════════════');

        $scoreColor = $summary['overall_score'] >= 85 ? 'success' :
                     ($summary['overall_score'] >= 70 ? 'warning' : 'error');

        $this->$scoreColor("Overall Security Score: {$summary['overall_score']}/100 ({$summary['security_status']})");

        if ($summary['critical_issues'] > 0) {
            $this->error("❗ Critical Issues: {$summary['critical_issues']}");
        }

        if ($summary['high_issues'] > 0) {
            $this->warning("⚠️ High Issues: {$summary['high_issues']}");
        }

        if ($summary['medium_issues'] > 0) {
            $this->info("🟡 Medium Issues: {$summary['medium_issues']}");
        }

        $this->info("Total Recommendations: {$summary['total_recommendations']}");
        $this->info("Environment: {$summary['environment']}");
        $this->info("Generated: {$summary['report_date']}");

        if ($output) {
            $this->info("Report saved: $output");
        }

        if ($email) {
            $this->info("Email prepared for: $email");
        }

        $this->line('');
        $this->success('✅ Security report generated successfully');
    }

    /**
     * Handle lockdown status check
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleLockdownStatus(array $args): int
    {
        $this->info('🔍 Lockdown Status Check');
        $this->line('');

        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        if (!file_exists($maintenanceFile)) {
            $this->success('✅ System is NOT in lockdown mode');
            return Command::SUCCESS;
        }

        $maintenanceData = json_decode(file_get_contents($maintenanceFile), true);

        if (!$maintenanceData || !($maintenanceData['enabled'] ?? false)) {
            $this->success('✅ System is NOT in lockdown mode');
            return Command::SUCCESS;
        }

        if (!($maintenanceData['lockdown_mode'] ?? false)) {
            $this->info('🔧 System is in maintenance mode (not security lockdown)');
            return Command::SUCCESS;
        }

        // Check if lockdown has expired
        $endTime = $maintenanceData['end_time'] ?? null;
        if ($endTime && time() > $endTime) {
            $this->warning('⚠️ Lockdown has EXPIRED but files still exist');
            $this->info('Run: php glueful security lockdown-disable --cleanup');
            return Command::SUCCESS;
        }

        $this->error('🚨 System is in ACTIVE LOCKDOWN mode');
        $this->line('');

        $this->displayLockdownDetails($maintenanceData);

        return Command::SUCCESS;
    }

    /**
     * Handle lockdown disable
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    protected function handleLockdownDisable(array $args): int
    {
        $cleanup = in_array('--cleanup', $args);
        $force = in_array('--force', $args);

        $this->warning('🚨 DISABLING SECURITY LOCKDOWN');

        if (!$force) {
            $this->line('');
            $this->warning('This will disable all lockdown protections!');
            $this->info('Are you sure you want to continue? (y/N)');

            $handle = fopen('php://stdin', 'r');
            $response = trim(fgets($handle));
            fclose($handle);

            if (strtolower($response) !== 'y') {
                $this->info('Lockdown disable cancelled');
                return Command::SUCCESS;
            }
        }

        try {
            $this->disableLockdownMode($cleanup);
            $this->success('✅ Lockdown has been disabled successfully');
            $this->info('System is now accessible normally');

            // Log the disable action
            $this->logLockdownDisable();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to disable lockdown: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Display lockdown details
     *
     * @param array $maintenanceData Maintenance data
     * @return void
     */
    private function displayLockdownDetails(array $maintenanceData): void
    {
        $this->info('📊 Lockdown Details:');
        $this->line('═══════════════════════════════════════════');

        if (isset($maintenanceData['reason'])) {
            $this->info("Reason: {$maintenanceData['reason']}");
        }

        if (isset($maintenanceData['start_time'])) {
            $startTime = date('Y-m-d H:i:s', $maintenanceData['start_time']);
            $this->info("Started: $startTime");
        }

        if (isset($maintenanceData['end_time'])) {
            $endTime = date('Y-m-d H:i:s', $maintenanceData['end_time']);
            $remaining = $maintenanceData['end_time'] - time();
            $this->info("Ends: $endTime");

            if ($remaining > 0) {
                $hours = floor($remaining / 3600);
                $minutes = floor(($remaining % 3600) / 60);
                $this->info("Time remaining: {$hours}h {$minutes}m");
            }
        }

        $this->line('');

        // Check for additional lockdown files
        $storagePath = config('app.paths.storage', './storage/');
        $lockdownFiles = [
            'lockdown_routes.json' => 'Endpoint restrictions',
            'blocked_ips.json' => 'IP blocks',
            'enhanced_logging.json' => 'Enhanced logging',
            'registration_disabled.json' => 'Registration disabled'
        ];

        $this->info('📋 Active Lockdown Components:');
        foreach ($lockdownFiles as $file => $description) {
            if (file_exists($storagePath . $file)) {
                $this->info("✓ $description");
            }
        }

        $this->line('');
        $this->info('To disable lockdown: php glueful security lockdown-disable');
    }

    /**
     * Disable lockdown mode
     *
     * @param bool $cleanup Whether to clean up all lockdown files
     * @return void
     */
    private function disableLockdownMode(bool $cleanup = true): void
    {
        $storagePath = config('app.paths.storage', './storage/');

        $this->info('Removing lockdown files...');

        // Remove maintenance file
        $maintenanceFile = $storagePath . 'framework/maintenance.json';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
            $this->info('✓ Maintenance mode disabled');
        }

        if ($cleanup) {
            // Remove all lockdown-related files
            $lockdownFiles = [
                'lockdown_routes.json' => 'Endpoint restrictions',
                'blocked_ips.json' => 'IP blocks',
                'enhanced_logging.json' => 'Enhanced logging',
                'registration_disabled.json' => 'Registration restrictions'
            ];

            foreach ($lockdownFiles as $file => $description) {
                $filePath = $storagePath . $file;
                if (file_exists($filePath)) {
                    unlink($filePath);
                    $this->info("✓ $description removed");
                }
            }
        }
    }

    /**
     * Log lockdown disable action
     *
     * @return void
     */
    private function logLockdownDisable(): void
    {
        try {
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
            $auditLogger->audit(
                'security',
                'lockdown_disabled',
                \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                [
                    'disabled_by' => 'security_command',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                    'timestamp' => time()
                ]
            );
        } catch (\Exception $e) {
            $this->warning("Failed to log lockdown disable: {$e->getMessage()}");
        }
    }

    /**
     * Get HTML template for report
     *
     * @return string HTML template
     */
    private function getHtmlTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{TITLE}}</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .header { 
            background: #2c3e50; color: white; padding: 30px; text-align: center; 
            border-radius: 8px; margin-bottom: 30px; 
        }
        .summary { 
            background: white; padding: 30px; margin: 20px 0; border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .score-high { color: #27ae60; font-weight: bold; font-size: 24px; }
        .score-medium { color: #f39c12; font-weight: bold; font-size: 24px; }
        .score-low { color: #e74c3c; font-weight: bold; font-size: 24px; }
        .section { 
            background: white; margin: 20px 0; padding: 25px; border-left: 4px solid #3498db; 
            border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .recommendation { 
            background: #fff3cd; padding: 15px; margin: 15px 0; border-radius: 6px; 
            border-left: 4px solid #ffc107; 
        }
        .critical { border-left-color: #dc3545; background: #f8d7da; }
        .high { border-left-color: #fd7e14; background: #fff3cd; }
        .medium { border-left-color: #20c997; background: #d1ecf1; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background: #f8f9fa; font-weight: 600; }
        .metric-good { color: #28a745; font-weight: bold; }
        .metric-warning { color: #ffc107; font-weight: bold; }
        .metric-danger { color: #dc3545; font-weight: bold; }
        h1, h2, h3 { color: #2c3e50; }
        .footer { text-align: center; margin-top: 40px; color: #6c757d; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{TITLE}}</h1>
        <p>Generated: {{GENERATED_AT}} | Period: {{REPORT_PERIOD}}</p>
        <p>Server: {{SERVER}} | Environment: {{ENVIRONMENT}}</p>
    </div>
    
    <div class="summary">
        <h2>Executive Summary</h2>
        <div class="{{SECURITY_STATUS_CLASS}}">
            Overall Security Score: {{OVERALL_SCORE}}/100 ({{SECURITY_STATUS}})
        </div>
        <table>
            <tr><th>Issue Level</th><th>Count</th><th>Status</th></tr>
            <tr>
                <td>Critical Issues</td><td>{{CRITICAL_ISSUES}}</td><td class="metric-danger">{{CRITICAL_ISSUES}}</td>
            </tr>
            <tr><td>High Issues</td><td>{{HIGH_ISSUES}}</td><td class="metric-warning">{{HIGH_ISSUES}}</td></tr>
            <tr><td>Medium Issues</td><td>{{MEDIUM_ISSUES}}</td><td class="metric-warning">{{MEDIUM_ISSUES}}</td></tr>
        </table>
        {{EXECUTIVE_SUMMARY}}
    </div>
    
    <div class="section">
        <h2>Detailed Security Findings</h2>
        {{DETAILED_FINDINGS}}
    </div>
    
    <div class="section">
        <h2>Security Recommendations</h2>
        {{RECOMMENDATIONS}}
    </div>
    
    <div class="footer">
        <p>Report generated by Glueful Security System</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get status class for CSS styling
     *
     * @param int $score Security score
     * @return string CSS class
     */
    private function getStatusClass(int $score): string
    {
        return match (true) {
            $score >= 85 => 'score-high',
            $score >= 70 => 'score-medium',
            default => 'score-low'
        };
    }

    /**
     * Get status text for score
     *
     * @param int $score Security score
     * @return string Status text
     */
    private function getStatusForScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'EXCELLENT',
            $score >= 70 => 'GOOD',
            $score >= 50 => 'FAIR',
            default => 'POOR'
        };
    }

    /**
     * Generate executive summary
     *
     * @param array $data Report data
     * @return string Executive summary HTML
     */
    private function generateExecutiveSummary(array $data): string
    {
        $score = $data['summary']['overall_score'];
        $critical = $data['summary']['critical_issues'];
        $high = $data['summary']['high_issues'];

        $summary = '<p>';

        if ($score >= 85) {
            $summary .= 'Your security posture is <strong>excellent</strong>. ';
        } elseif ($score >= 70) {
            $summary .= 'Your security posture is <strong>good</strong> but has room for improvement. ';
        } else {
            $summary .= 'Your security posture <strong>requires immediate attention</strong>. ';
        }

        if ($critical > 0) {
            $summary .= "There are <span class='metric-danger'>{$critical} critical issues</span> that need " .
                       "immediate resolution. ";
        }

        if ($high > 0) {
            $summary .= "Additionally, there are <span class='metric-warning'>{$high} high-priority issues</span> " .
                       "to address. ";
        }

        $summary .= 'Please review the detailed findings and recommendations below.';
        $summary .= '</p>';

        return $summary;
    }

    /**
     * Generate detailed findings
     *
     * @param array $data Report data
     * @return string Detailed findings HTML
     */
    private function generateDetailedFindings(array $data): string
    {
        $html = '';

        // Security Configuration
        $html .= '<h3>Security Configuration</h3>';
        $prodScore = $data['security_config']['production_readiness']['score'];
        $html .= "<p>Production Readiness Score: <strong>{$prodScore}/100</strong></p>";

        if (!empty($data['security_config']['production_readiness']['warnings'])) {
            $html .= '<h4>Configuration Warnings:</h4><ul>';
            foreach ($data['security_config']['production_readiness']['warnings'] as $warning) {
                $html .= "<li class='metric-danger'>$warning</li>";
            }
            $html .= '</ul>';
        }

        // Vulnerabilities
        if (isset($data['vulnerabilities'])) {
            $html .= '<h3>Vulnerability Assessment</h3>';
            $vuln = $data['vulnerabilities']['summary'];
            $html .= "<p>Total Vulnerabilities Found: <strong>{$vuln['total_vulnerabilities']}</strong></p>";

            if ($vuln['critical'] > 0) {
                $html .= "<p class='metric-danger'>Critical: {$vuln['critical']}</p>";
            }
            if ($vuln['high'] > 0) {
                $html .= "<p class='metric-warning'>High: {$vuln['high']}</p>";
            }
        }

        return $html;
    }

    /**
     * Format recommendations as HTML
     *
     * @param array $recommendations Recommendations array
     * @return string Formatted HTML
     */
    private function formatRecommendationsHtml(array $recommendations): string
    {
        if (empty($recommendations)) {
            return '<p>No specific recommendations at this time.</p>';
        }

        $html = '';
        foreach ($recommendations as $rec) {
            $class = match ($rec['priority']) {
                'critical' => 'critical',
                'high' => 'high',
                'medium' => 'medium',
                default => ''
            };

            $html .= "<div class='recommendation $class'>";
            $html .= "<h4>[{$rec['priority']}] {$rec['title']}</h4>";
            $html .= "<p>{$rec['description']}</p>";
            $html .= "<p><strong>Action:</strong> {$rec['action']}</p>";
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Create email body
     *
     * @param array $summary Report summary
     * @return string Email body
     */
    private function createEmailBody(array $summary): string
    {
        $body = "Security Assessment Report\n";
        $body .= "========================\n\n";
        $body .= "Overall Security Score: {$summary['overall_score']}/100 ({$summary['security_status']})\n";
        $body .= "Critical Issues: {$summary['critical_issues']}\n";
        $body .= "High Issues: {$summary['high_issues']}\n";
        $body .= "Medium Issues: {$summary['medium_issues']}\n";
        $body .= "Total Recommendations: {$summary['total_recommendations']}\n\n";
        $body .= "Environment: {$summary['environment']}\n";
        $body .= "Generated: {$summary['report_date']}\n\n";
        $body .= "Please see attached report for detailed analysis and recommendations.";

        return $body;
    }

    /**
     * Assess key strength
     *
     * @param string|null $key Key to assess
     * @return string Assessment result
     */
    private function assessKeyStrength(?string $key): string
    {
        if (empty($key)) {
            return 'Not Set';
        }

        $length = strlen($key);

        return match (true) {
            $length >= 64 => 'Strong',
            $length >= 32 => 'Good',
            $length >= 16 => 'Weak',
            default => 'Very Weak'
        };
    }

    /**
     * Check cache health
     *
     * @return array Cache health status
     */
    private function checkCacheHealth(): array
    {
        try {
            return \Glueful\Services\HealthService::checkCache();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check storage security
     *
     * @return array Storage security status
     */
    private function checkStorageSecurity(): array
    {
        $storagePath = config('app.paths.storage', './storage/');
        $issues = [];

        // Check if storage directory is writable
        if (!is_writable($storagePath)) {
            $issues[] = 'Storage directory not writable';
        }

        // Check for sensitive files in public areas
        $sensitiveFiles = ['.env', 'config/', 'database/'];
        foreach ($sensitiveFiles as $file) {
            if (file_exists($file) && is_readable($file)) {
                $perms = fileperms($file);
                if ($perms & 0004) { // World readable
                    $issues[] = "$file is world-readable";
                }
            }
        }

        return [
            'status' => empty($issues) ? 'ok' : 'warning',
            'issues' => $issues
        ];
    }

    /**
     * Get authentication metrics
     *
     * @param int $days Days to analyze
     * @return array Authentication metrics
     */
    private function getAuthenticationMetrics(int $days): array
    {
        // Placeholder implementation
        return [
            'total_logins' => 0,
            'successful_logins' => 0,
            'failed_logins' => 0,
            'unique_users' => 0,
            'success_rate' => 0
        ];
    }

    /**
     * Get failed authentication attempts
     *
     * @param int $days Days to analyze
     * @return array Failed attempts data
     */
    private function getFailedAuthenticationAttempts(int $days): array
    {
        return [
            'count' => 0,
            'unique_ips' => 0,
            'top_ips' => []
        ];
    }

    /**
     * Analyze password policy
     *
     * @return array Password policy analysis
     */
    private function analyzePasswordPolicy(): array
    {
        return [
            'min_length' => config('auth.password.min_length', 8),
            'requires_uppercase' => config('auth.password.uppercase', false),
            'requires_numbers' => config('auth.password.numbers', false),
            'requires_symbols' => config('auth.password.symbols', false)
        ];
    }

    /**
     * Analyze session security
     *
     * @return array Session security analysis
     */
    private function analyzeSessionSecurity(): array
    {
        return [
            'driver' => config('session.driver', 'file'),
            'lifetime' => config('session.lifetime', 120),
            'secure' => config('session.secure', false),
            'http_only' => config('session.http_only', true)
        ];
    }

    /**
     * Analyze token management
     *
     * @return array Token management analysis
     */
    private function analyzeTokenManagement(): array
    {
        return [
            'jwt_algorithm' => config('jwt.algorithm', 'HS256'),
            'token_ttl' => config('jwt.ttl', 3600),
            'refresh_enabled' => config('jwt.refresh', false)
        ];
    }

    /**
     * Get suspicious auth activity
     *
     * @param int $days Days to analyze
     * @return array Suspicious activity data
     */
    private function getSuspiciousAuthActivity(int $days): array
    {
        return [
            'brute_force_attempts' => 0,
            'suspicious_locations' => 0,
            'unusual_times' => 0
        ];
    }

    /**
     * Additional placeholder methods for analysis functions
     */
    private function analyzePermissionSystem(): array
    {
        return ['status' => 'ok'];
    }

    private function analyzeRoleManagement(): array
    {
        return ['status' => 'ok'];
    }

    private function analyzeApiAccess(int $days): array
    {
        return ['requests' => 0];
    }

    private function analyzeAdminAccess(int $days): array
    {
        return ['accesses' => 0];
    }

    private function checkPrivilegeEscalation(int $days): array
    {
        return ['incidents' => 0];
    }

    private function getAuditEventCount(int $_days): int
    {
        return 0;
    }

    private function getSecurityEventCount(int $_days): int
    {
        return 0;
    }

    private function getAdminEventCount(int $_days): int
    {
        return 0;
    }

    private function getErrorEventCount(int $_days): int
    {
        return 0;
    }

    private function getEventTypeBreakdown(int $_days): array
    {
        return [];
    }

    private function assessOWASPCompliance(): array
    {
        return ['score' => 75];
    }

    private function assessGDPRCompliance(): array
    {
        return ['compliant' => true];
    }

    private function assessSecurityHeaders(): array
    {
        return ['configured' => false];
    }

    private function assessDataProtection(): array
    {
        return ['encryption' => true];
    }

    private function assessLoggingMonitoring(): array
    {
        return ['adequate' => true];
    }

    private function generateVulnerabilityRecommendations(array $results): array
    {
        return [];
    }

    private function getSecurityResponseTimes(int $days): array
    {
        return [];
    }

    private function getThreatDetectionMetrics(int $days): array
    {
        return [];
    }

    private function getIncidentResponseMetrics(int $days): array
    {
        return [];
    }

    private function getSecurityTrainingMetrics(int $days): array
    {
        return [];
    }

    private function getPatchManagementMetrics(int $days): array
    {
        return [];
    }

    private function generateComplianceRecommendations(array $data): array
    {
        return [];
    }

    /**
     * Parse duration string to seconds
     *
     * @param string $duration Duration string like "30m", "2h", "1d"
     * @return int Duration in seconds
     */
    private function parseDuration(string $duration): int
    {
        $unit = substr($duration, -1);
        $value = (int) substr($duration, 0, -1);

        return match ($unit) {
            'm' => $value * 60,           // minutes
            'h' => $value * 3600,         // hours
            'd' => $value * 86400,        // days
            default => 3600               // default 1 hour
        };
    }

    /**
     * Enable maintenance mode
     *
     * @param string $reason Lockdown reason
     * @param int $endTime End time timestamp
     * @return void
     */
    private function enableMaintenanceMode(string $reason, int $endTime): void
    {
        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        // Ensure directory exists
        $dir = dirname($maintenanceFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'enabled' => true,
            'reason' => $reason,
            'start_time' => time(),
            'end_time' => $endTime,
            'lockdown_mode' => true,
            'message' => 'System temporarily unavailable due to security maintenance'
        ];

        file_put_contents($maintenanceFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Disable API endpoints based on severity
     *
     * @param string $severity Lockdown severity
     * @return void
     */
    private function disableApiEndpoints(string $severity): void
    {
        $disabledEndpoints = $this->getEndpointsToDisable($severity);

        $storagePath = config('app.paths.storage', './storage/');
        $lockdownRoutes = $storagePath . 'lockdown_routes.json';

        file_put_contents($lockdownRoutes, json_encode([
            'disabled_endpoints' => $disabledEndpoints,
            'allowed_endpoints' => ['/health', '/lockdown-status', '/api/auth/login'],
            'severity' => $severity,
            'created_at' => time()
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get endpoints to disable based on severity
     *
     * @param string $severity Lockdown severity
     * @return array
     */
    private function getEndpointsToDisable(string $severity): array
    {
        return match ($severity) {
            'critical' => ['*'], // Disable everything except allowed endpoints
            'high' => ['/api/admin/*', '/api/users/create', '/api/files/upload', '/api/extensions/*'],
            'medium' => ['/api/admin/*', '/api/users/create', '/api/files/upload'],
            'low' => ['/api/admin/delete', '/api/admin/reset', '/api/extensions/install'],
            default => []
        };
    }

    /**
     * Block suspicious IP addresses
     *
     * @param string $severity Lockdown severity
     * @return array Blocked IP addresses
     */
    private function blockSuspiciousIPs(string $severity): array
    {
        $blockedIps = [];

        try {
            // Get recent failed authentication attempts
            $suspiciousIps = $this->getSuspiciousIPs($severity);

            foreach ($suspiciousIps as $ip => $attempts) {
                if ($this->shouldBlockIP($ip, $attempts, $severity)) {
                    $this->blockIP($ip, 'Security lockdown');
                    $blockedIps[] = $ip;
                }
            }
        } catch (\Exception $e) {
            $this->warning("Failed to block suspicious IPs: {$e->getMessage()}");
        }

        return $blockedIps;
    }

    /**
     * Get suspicious IP addresses from audit logs
     *
     * @param string $severity Lockdown severity
     * @return array
     */
    private function getSuspiciousIPs(string $severity): array
    {
        // Get failed authentication attempts - this would need to be implemented in AuditLogger
        // For now, return empty array as placeholder
        return [];
    }

    /**
     * Determine if IP should be blocked
     *
     * @param string $ip IP address
     * @param int $attempts Number of failed attempts
     * @param string $severity Lockdown severity
     * @return bool
     */
    private function shouldBlockIP(string $ip, int $attempts, string $severity): bool
    {
        // Don't block localhost or private IPs
        if (in_array($ip, ['127.0.0.1', '::1']) || $this->isPrivateIP($ip)) {
            return false;
        }

        $threshold = match ($severity) {
            'critical' => 3,
            'high' => 5,
            'medium' => 10,
            default => 20
        };

        return $attempts >= $threshold;
    }

    /**
     * Check if IP is private
     *
     * @param string $ip IP address
     * @return bool
     */
    private function isPrivateIP(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Block an IP address
     *
     * @param string $ip IP address to block
     * @param string $reason Block reason
     * @return void
     */
    private function blockIP(string $ip, string $reason): void
    {
        $storagePath = config('app.paths.storage', './storage/');
        $blockedIpsFile = $storagePath . 'blocked_ips.json';

        $blockedIps = [];
        if (file_exists($blockedIpsFile)) {
            $blockedIps = json_decode(file_get_contents($blockedIpsFile), true) ?: [];
        }

        $blockedIps[$ip] = [
            'reason' => $reason,
            'blocked_at' => time(),
            'expires_at' => time() + 86400 // 24 hours
        ];

        file_put_contents($blockedIpsFile, json_encode($blockedIps, JSON_PRETTY_PRINT));
    }

    /**
     * Enable enhanced logging
     *
     * @param string $lockdownId Lockdown ID
     * @return void
     */
    private function enableEnhancedLogging(string $lockdownId): void
    {
        $storagePath = config('app.paths.storage', './storage/');
        $enhancedLoggingFile = $storagePath . 'enhanced_logging.json';

        $logConfig = [
            'lockdown_mode' => true,
            'lockdown_id' => $lockdownId,
            'enhanced_logging' => true,
            'log_level' => 'debug',
            'log_all_requests' => true,
            'log_all_responses' => true,
            'enabled_at' => time()
        ];

        file_put_contents($enhancedLoggingFile, json_encode($logConfig, JSON_PRETTY_PRINT));
    }

    /**
     * Send administrator alerts
     *
     * @param string $reason Lockdown reason
     * @param string $severity Lockdown severity
     * @param string $lockdownId Lockdown ID
     * @return void
     */
    private function sendAdministratorAlerts(string $reason, string $severity, string $lockdownId): void
    {
        try {
            $adminEmails = config('security.admin_emails', []);

            if (empty($adminEmails)) {
                $this->warning('No admin emails configured for alerts');
                return;
            }

            foreach ($adminEmails as $email) {
                $this->sendLockdownAlert($email, $reason, $severity, $lockdownId);
            }

            // Send webhook alerts if configured
            $this->sendWebhookAlerts($reason, $severity, $lockdownId);
        } catch (\Exception $e) {
            $this->warning("Failed to send admin alerts: {$e->getMessage()}");
        }
    }

    /**
     * Send lockdown alert email
     *
     * @param string $email Admin email
     * @param string $reason Lockdown reason
     * @param string $severity Lockdown severity
     * @param string $lockdownId Lockdown ID
     * @return void
     */
    private function sendLockdownAlert(string $email, string $reason, string $severity, string $lockdownId): void
    {
        // This would integrate with your email service
        // For now, just log the alert
        error_log(
            "SECURITY ALERT: Lockdown activated - ID: $lockdownId, " .
            "Reason: $reason, Severity: $severity, Admin: $email"
        );
    }

    /**
     * Send webhook alerts
     *
     * @param string $reason Lockdown reason
     * @param string $severity Lockdown severity
     * @param string $lockdownId Lockdown ID
     * @return void
     */
    private function sendWebhookAlerts(string $reason, string $severity, string $lockdownId): void
    {
        $webhookUrl = config('security.alert_webhook_url');

        if (empty($webhookUrl)) {
            return;
        }

        $payload = [
            'type' => 'security_lockdown',
            'severity' => $severity,
            'reason' => $reason,
            'lockdown_id' => $lockdownId,
            'timestamp' => time(),
            'server' => gethostname()
        ];

        // Send webhook (simplified implementation)
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload)
            ]
        ]);

        @file_get_contents($webhookUrl, false, $context);
    }

    /**
     * Force password resets for all users
     *
     * @param string $lockdownId Lockdown ID
     * @return void
     */
    private function forceAllPasswordResets(string $lockdownId): void
    {
        try {
            $queryBuilder = $this->getQueryBuilder();

            // Get all active users
            $users = $queryBuilder->select('users', ['id', 'email'])
                ->where(['status' => 'active'])
                ->get();

            foreach ($users as $user) {
                // Set password reset required flag
                $queryBuilder->update('users', [
                    'password_reset_required' => true,
                    'password_reset_reason' => $lockdownId
                ], ['id' => $user['id']]);
            }

            $this->info("Flagged " . count($users) . " users for mandatory password reset");
        } catch (\Exception $e) {
            $this->warning("Failed to force password resets: {$e->getMessage()}");
        }
    }

    /**
     * Disable user registrations
     *
     * @param int $endTime End time timestamp
     * @return void
     */
    private function disableUserRegistrations(int $endTime): void
    {
        $storagePath = config('app.paths.storage', './storage/');
        $registrationFile = $storagePath . 'registration_disabled.json';

        $data = [
            'disabled' => true,
            'reason' => 'Security lockdown',
            'disabled_at' => time(),
            'expires_at' => $endTime
        ];

        file_put_contents($registrationFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Record lockdown event in audit log
     *
     * @param string $lockdownId Lockdown ID
     * @param string $reason Lockdown reason
     * @param string $severity Lockdown severity
     * @param int $startTime Start time
     * @param int $endTime End time
     * @param array $actions Actions taken
     * @return void
     */
    private function recordLockdownEvent(
        string $lockdownId,
        string $reason,
        string $severity,
        int $startTime,
        int $endTime,
        array $actions
    ): void {
        try {
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
            $auditLogger->audit(
                'security',
                'emergency_lockdown',
                \Glueful\Logging\AuditEvent::SEVERITY_CRITICAL,
                [
                    'lockdown_id' => $lockdownId,
                    'reason' => $reason,
                    'severity' => $severity,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'actions_taken' => $actions,
                    'initiated_by' => 'security_command',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'CLI'
                ]
            );
        } catch (\Exception $e) {
            $this->warning("Failed to record lockdown event: {$e->getMessage()}");
        }
    }

    /**
     * Display lockdown summary
     *
     * @param string $lockdownId Lockdown ID
     * @param string $reason Lockdown reason
     * @param string $severity Lockdown severity
     * @param array $blockedIps Blocked IP addresses
     * @return void
     */
    private function displayLockdownSummary(
        string $lockdownId,
        string $reason,
        string $severity,
        array $blockedIps
    ): void {
        $this->line('');
        $this->info('📊 Lockdown Summary');
        $this->line('═══════════════════════════════════════════');
        $this->info("ID: $lockdownId");
        $this->info("Reason: $reason");
        $this->info("Severity: $severity");
        $this->info("Blocked IPs: " . count($blockedIps));

        if (!empty($blockedIps)) {
            $this->info("Blocked IP addresses:");
            foreach (array_slice($blockedIps, 0, 5) as $ip) {
                $this->info("  • $ip");
            }
            if (count($blockedIps) > 5) {
                $this->info("  ... and " . (count($blockedIps) - 5) . " more");
            }
        }

        $this->line('');
    }

    /**
     * Log lockdown failure
     *
     * @param string $reason Lockdown reason
     * @param string $severity Lockdown severity
     * @param \Exception $exception Exception that occurred
     * @return void
     */
    private function logLockdownFailure(string $reason, string $severity, \Exception $exception): void
    {
        try {
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
            $auditLogger->audit(
                'security',
                'lockdown_failure',
                \Glueful\Logging\AuditEvent::SEVERITY_ERROR,
                [
                    'reason' => $reason,
                    'severity' => $severity,
                    'error_message' => $exception->getMessage(),
                    'stack_trace' => $exception->getTraceAsString(),
                    'initiated_by' => 'security_command',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'CLI'
                ]
            );
        } catch (\Exception $e) {
            error_log("Failed to log lockdown failure: {$e->getMessage()}");
        }
    }


    /**
     * Clean up resources to prevent memory leaks
     *
     * @return void
     */
    public function cleanup(): void
    {
        // Container cleanup is handled automatically by the main DI container
    }

    /**
     * Destructor - ensure cleanup
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
