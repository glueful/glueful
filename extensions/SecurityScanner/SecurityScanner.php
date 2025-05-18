<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\SecurityScanner\Scanner\CodeScanner;
use Glueful\Extensions\SecurityScanner\Scanner\DependencyScanner;
use Glueful\Extensions\SecurityScanner\Scanner\ApiScanner;
use Glueful\Extensions\SecurityScanner\Dashboard\SecurityDashboard;
use Glueful\Extensions\SecurityScanner\Notifications\SecurityNotificationProvider;
use Glueful\Logging\LogManager;

/**
 * Security Scanner Extension
 * @description Comprehensive security scanning system for code, dependencies, and APIs
 * @license MIT
 * @version 1.0.0
 * @author Glueful Security Team
 *
 * Provides integrated security scanning capabilities for Glueful:
 * - Static code analysis for security vulnerabilities
 * - Dependency vulnerability scanning
 * - Dynamic API endpoint testing
 * - Security dashboard for centralized monitoring
 *
 * Features:
 * - Automated vulnerability detection
 * - Best practice enforcement
 * - Security advisory integration
 * - Risk assessment and reporting
 * - Remediation tracking
 *
 * @package Glueful\Extensions
 */
class SecurityScanner extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];

    /** @var array Available scanners */
    private static array $availableScanners = ['code', 'dependency', 'api'];

    /** @var CodeScanner Code scanner instance */
    private static ?CodeScanner $codeScanner = null;

    /** @var DependencyScanner Dependency scanner instance */
    private static ?DependencyScanner $dependencyScanner = null;

    /** @var ApiScanner API scanner instance */
    private static ?ApiScanner $apiScanner = null;

    /** @var SecurityDashboard Security dashboard instance */
    private static ?SecurityDashboard $dashboard = null;

    /** @var LogManager Logger instance */
    private static ?LogManager $logger = null;

    /** @var SecurityNotificationProvider Notification provider instance */
    private static ?SecurityNotificationProvider $notificationProvider = null;

    /**
     * Initialize extension
     *
     * Sets up security scanners and registers them with the system.
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Initialize logger
        self::$logger = new LogManager('security_scanner');

        try {
            // Load configuration
            self::loadConfig();

            // Initialize scanners
            self::initializeScanners();

            self::$logger->info('SecurityScanner extension initialized successfully');
        } catch (\Exception $e) {
            if (self::$logger) {
                self::$logger->error('Error initializing SecurityScanner extension: ' . $e->getMessage(), [
                    'exception' => $e
                ]);
            } else {
                error_log('Error initializing SecurityScanner extension: ' . $e->getMessage());
            }
        }
    }

    /**
     * Initialize scanner instances
     *
     * @return void
     */
    private static function initializeScanners(): void
    {
        try {
            $enabledScanners = self::$config['enabled_scanners'] ?? ['code', 'dependency', 'api'];

            // Initialize code scanner if enabled
            if (in_array('code', $enabledScanners)) {
                self::$codeScanner = new CodeScanner(self::$config['code_scanner'] ?? []);
            }

            // Initialize dependency scanner if enabled
            if (in_array('dependency', $enabledScanners)) {
                self::$dependencyScanner = new DependencyScanner(self::$config['dependency_scanner'] ?? []);
            }

            // Initialize API scanner if enabled
            if (in_array('api', $enabledScanners)) {
                self::$apiScanner = new ApiScanner(self::$config['api_scanner'] ?? []);
            }

            // Initialize dashboard with try/catch to prevent failures
            try {
                self::$dashboard = new SecurityDashboard(self::$config['dashboard'] ?? []);
            } catch (\Exception $e) {
                if (self::$logger) {
                    self::$logger->error('Error initializing dashboard: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            if (self::$logger) {
                self::$logger->error('Error initializing scanners: ' . $e->getMessage());
            }
        }
    }

    /**
     * Register extension-provided services
     *
     * @return void
     */
    public static function registerServices(): void
    {
        try {
            // Register with scheduler if available
            if (class_exists('\\Glueful\\Scheduler\\JobScheduler')) {
                $scheduler = \Glueful\Scheduler\JobScheduler::getInstance();

                // Get scan schedule configuration
                $scanSchedule = self::$config['scan_schedule'] ?? [];

                // Register code scanner job - FIX ORDER OF PARAMETERS
                if (isset($scanSchedule['code']) && in_array('code', self::$config['enabled_scanners'] ?? [])) {
                    $scheduler->register(
                        $scanSchedule['code'] ?? '0 3 * * *', // Schedule first
                        [self::class, 'runCodeScan'], // Callable second (use existing method)
                        'security_scan_code' // Name third
                    );
                }

                // Register dependency scanner job - FIX ORDER OF PARAMETERS
                if (
                    isset($scanSchedule['dependency']) &&
                    in_array('dependency', self::$config['enabled_scanners'] ?? [])
                ) {
                    $scheduler->register(
                        $scanSchedule['dependency'], // Schedule first
                        [self::class, 'runDependencyScan'], // Callable second
                        'security_scan_dependencies' // Name third
                    );
                }

                // Register API scanner job - FIX ORDER OF PARAMETERS
                if (isset($scanSchedule['api']) && in_array('api', self::$config['enabled_scanners'] ?? [])) {
                    $scheduler->register(
                        $scanSchedule['api'], // Schedule first
                        [self::class, 'runApiScan'], // Callable second
                        'security_scan_api' // Name third
                    );
                }

                // Register full security scan job (weekly) - FIX ORDER OF PARAMETERS
                $scheduler->register(
                    'weekly', // Schedule first
                    [self::class, 'runFullScan'], // Callable second
                    'security_scan_full' // Name third
                );

                if (self::$logger) {
                    self::$logger->info('Security scan jobs registered with scheduler');
                }
            }

            // Register our notification channel if we have one
            if (class_exists('\\Glueful\\Notifications\\Services\\ChannelManager')) {
                try {
                    // Check if we have a SecurityNotificationProvider
                    if (
                        class_exists(
                            '\\Glueful\\Extensions\\SecurityScanner\\Notifications\\SecurityNotificationProvider'
                        )
                        &&
                        self::$notificationProvider !== null
                    ) {
                        // Get the channel manager directly without using Container
                        $channelManager = new \Glueful\Notifications\Services\ChannelManager();

                        // Register the provider with the channel manager
                        self::$notificationProvider->register($channelManager);

                        if (self::$logger) {
                            self::$logger->info('Security notification provider registered successfully');
                        }
                    }
                } catch (\Exception $e) {
                    if (self::$logger) {
                        self::$logger->error('Error registering security notification provider: ' . $e->getMessage());
                    }
                }
            }

            if (self::$logger) {
                self::$logger->info('SecurityScanner services registered successfully');
            }
        } catch (\Exception $e) {
            if (self::$logger) {
                self::$logger->error('Error registering SecurityScanner services: ' . $e->getMessage());
            } else {
                error_log('Error registering SecurityScanner services: ' . $e->getMessage());
            }
        }
    }

    /**
     * Register extension-specific routes
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        // Routes will be defined in separate file and auto-loaded
        // These would include dashboard routes and API endpoints for scanner control
    }

    /**
     * Load configuration for the extension
     *
     * @return void
     */
    private static function loadConfig(): void
    {
        // Default configuration
        $defaultConfig = [
            'enabled_scanners' => ['code', 'dependency', 'api'],
            'scan_schedule' => [
                'code' => 'daily',
                'dependency' => 'daily',
                'api' => 'weekly'
            ],
            'notification_channels' => ['email', 'dashboard'],
            'code_scanner' => [
                'scan_depth' => 'medium',
                'ignore_patterns' => ['vendor/*', 'node_modules/*']
            ],
            'dependency_scanner' => [
                'composer_packages' => true,
                'npm_packages' => true
            ],
            'api_scanner' => [
                'endpoints' => 'auto-discover',
                'test_methods' => ['GET', 'POST', 'PUT', 'DELETE']
            ],
            'dashboard' => [
                'risk_threshold' => 'medium',
                'history_retention_days' => 90
            ]
        ];

        // Try to load config from file
        $configPath = __DIR__ . '/config.php';
        if (file_exists($configPath)) {
            $loadedConfig = require $configPath;
            self::$config = array_merge($defaultConfig, $loadedConfig);
        } else {
            self::$config = $defaultConfig;
        }
    }

    /**
     * Run full security scan
     *
     * @return array Scan results
     */
    public static function runFullScan(): array
    {
        $results = [];
        $enabledScanners = self::$config['enabled_scanners'] ?? [];

        // Run code scan if enabled
        if (in_array('code', $enabledScanners) && self::$codeScanner) {
            $results['code'] = self::runCodeScan();
        }

        // Run dependency scan if enabled
        if (in_array('dependency', $enabledScanners) && self::$dependencyScanner) {
            $results['dependency'] = self::runDependencyScan();
        }

        // Run API scan if enabled
        if (in_array('api', $enabledScanners) && self::$apiScanner) {
            $results['api'] = self::runApiScan();
        }

        // Generate and store report
        if (self::$dashboard) {
            self::$dashboard->generateReport(json_encode($results));
        }

        return $results;
    }

    /**
     * Run code security scan
     *
     * @return array Scan results
     */
    public static function runCodeScan(): array
    {
        if (!self::$codeScanner) {
            self::$codeScanner = new CodeScanner(self::$config['code_scanner'] ?? []);
        }

        return self::$codeScanner->scan();
    }

    /**
     * Run dependency security scan
     *
     * @return array Scan results
     */
    public static function runDependencyScan(): array
    {
        if (!self::$dependencyScanner) {
            self::$dependencyScanner = new DependencyScanner(self::$config['dependency_scanner'] ?? []);
        }

        return self::$dependencyScanner->scan();
    }

    /**
     * Run API security scan
     *
     * @return array Scan results
     */
    public static function runApiScan(): array
    {
        if (!self::$apiScanner) {
            self::$apiScanner = new ApiScanner(self::$config['api_scanner'] ?? []);
        }

        return self::$apiScanner->scan();
    }

    /**
     * Get extension configuration
     *
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Get extension metadata
     *
     * @return array Extension metadata for admin interface
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'Security Scanner',
            'description' => 'Comprehensive security scanning system for code, dependencies, and APIs',
            'version' => '1.0.0',
            'author' => 'Glueful Security Team',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => []
            ]
        ];
    }

    /**
     * Get extension dependencies
     *
     * @return array List of extension dependencies
     */
    public static function getDependencies(): array
    {
        // Currently no dependencies on other extensions
        return [];
    }

    /**
     * Check environment-specific configuration
     *
     * @param string $environment Current environment (dev, staging, production)
     * @return bool Whether the extension should be enabled in this environment
     */
    public static function isEnabledForEnvironment(string $environment): bool
    {
        // Enable for all environments but with different configurations
        return true;
    }

    /**
     * Validate extension health
     *
     * @return array Health status with 'healthy', 'issues', and 'metrics' keys
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'execution_time' => 0,
            'scans_performed' => 0,
            'vulnerabilities_detected' => 0
        ];

        // Start execution time tracking
        $startTime = microtime(true);

        // Check configuration
        if (empty(self::$config)) {
            self::loadConfig();
            if (empty(self::$config)) {
                $healthy = false;
                $issues[] = 'Failed to load extension configuration';
            }
        }

        // Check scanner instances
        $enabledScanners = self::$config['enabled_scanners'] ?? [];

        if (in_array('code', $enabledScanners) && !self::$codeScanner) {
            $healthy = false;
            $issues[] = 'Code Scanner not initialized';
        }

        if (in_array('dependency', $enabledScanners) && !self::$dependencyScanner) {
            $healthy = false;
            $issues[] = 'Dependency Scanner not initialized';
        }

        if (in_array('api', $enabledScanners) && !self::$apiScanner) {
            $healthy = false;
            $issues[] = 'API Scanner not initialized';
        }

        if (!self::$dashboard) {
            $healthy = false;
            $issues[] = 'Security Dashboard not initialized';
        }

        // Calculate execution time
        $metrics['execution_time'] = microtime(true) - $startTime;

        return [
            'healthy' => $healthy,
            'issues' => $issues,
            'metrics' => $metrics
        ];
    }

    /**
     * Get extension resource usage
     *
     * @return array Resource usage metrics
     */
    public static function getResourceUsage(): array
    {
        // Basic resource measurements
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'enabled_scanners' => count(self::$config['enabled_scanners'] ?? [])
        ];
    }
}
