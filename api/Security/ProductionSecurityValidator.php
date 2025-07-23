<?php

declare(strict_types=1);

namespace Glueful\Security;

/**
 * Production Security Validator
 *
 * Validates security configuration for production environments to prevent
 * information disclosure and ensure proper security settings.
 *
 * @package Glueful\Security
 */
class ProductionSecurityValidator
{
    /** @var array Required secure settings for production */
    private const REQUIRED_PRODUCTION_SETTINGS = [
        'APP_DEBUG' => false,
        'API_DOCS_ENABLED' => false,
        'API_DEBUG_MODE' => false,
    ];

    /** @var array Allowed environments that can show debug information */
    private const DEBUG_ALLOWED_ENVIRONMENTS = ['development', 'local', 'testing'];

    /**
     * Validate production security settings
     *
     * @return array Validation results
     */
    public static function validateProductionSecurity(): array
    {
        $environment = env('APP_ENV', 'production');
        $issues = [];
        $warnings = [];
        $isProduction = $environment === 'production';

        // Check critical security settings for production
        if ($isProduction) {
            foreach (self::REQUIRED_PRODUCTION_SETTINGS as $setting => $expectedValue) {
                $currentValue = self::getConfigValue($setting);

                if ($currentValue !== $expectedValue) {
                    $issues[] = [
                        'type' => 'CRITICAL',
                        'setting' => $setting,
                        'current' => $currentValue,
                        'expected' => $expectedValue,
                        'message' => "Security risk: {$setting} should be {$expectedValue} in production"
                    ];
                }
            }
        }

        // Check debug information exposure
        $debugInfo = self::checkDebugInformationExposure();
        if (!empty($debugInfo['risks'])) {
            $issues = array_merge($issues, $debugInfo['risks']);
        }

        // Check error handling configuration
        $errorConfig = self::validateErrorHandling();
        if (!empty($errorConfig['issues'])) {
            $issues = array_merge($issues, $errorConfig['issues']);
        }
        if (!empty($errorConfig['warnings'])) {
            $warnings = array_merge($warnings, $errorConfig['warnings']);
        }

        // Check environment consistency
        $envConsistency = self::checkEnvironmentConsistency();
        if (!empty($envConsistency)) {
            $warnings = array_merge($warnings, $envConsistency);
        }

        return [
            'environment' => $environment,
            'is_production' => $isProduction,
            'security_score' => self::calculateSecurityScore($issues, $warnings),
            'critical_issues' => $issues,
            'warnings' => $warnings,
            'recommendations' => self::getRecommendations($issues, $warnings),
            'passed' => empty($issues)
        ];
    }

    /**
     * Check for debug information exposure risks
     *
     * @return array Debug exposure analysis
     */
    private static function checkDebugInformationExposure(): array
    {
        $risks = [];
        $environment = env('APP_ENV', 'production');
        $debugMode = config('app.debug', false);
        $apiDebug = config('api.debug_mode', false);

        // Check if debug is enabled in non-debug environments
        if (!in_array($environment, self::DEBUG_ALLOWED_ENVIRONMENTS) && $debugMode) {
            $risks[] = [
                'type' => 'HIGH',
                'category' => 'DEBUG_EXPOSURE',
                'message' => 'Debug mode is enabled in non-development environment',
                'details' => "APP_ENV={$environment} but APP_DEBUG=true"
            ];
        }

        // Check API debug mode
        if (!in_array($environment, self::DEBUG_ALLOWED_ENVIRONMENTS) && $apiDebug) {
            $risks[] = [
                'type' => 'HIGH',
                'category' => 'API_DEBUG_EXPOSURE',
                'message' => 'API debug mode is enabled in non-development environment',
                'details' => "This may expose internal API structure and error details"
            ];
        }

        // Check for potentially exposed PHP settings
        $phpSettings = self::checkPHPSecuritySettings();
        if (!empty($phpSettings)) {
            $risks = array_merge($risks, $phpSettings);
        }

        return ['risks' => $risks];
    }

    /**
     * Validate error handling configuration
     *
     * @return array Error handling validation results
     */
    private static function validateErrorHandling(): array
    {
        $issues = [];
        $warnings = [];
        $environment = env('APP_ENV', 'production');

        // Check if error reporting is appropriate for environment
        $errorReporting = error_reporting();
        if ($environment === 'production' && $errorReporting !== 0 && $errorReporting !== E_ERROR) {
            $warnings[] = [
                'type' => 'MEDIUM',
                'category' => 'ERROR_REPORTING',
                'message' => 'Error reporting level may be too verbose for production',
                'current_level' => $errorReporting
            ];
        }

        // Check display_errors setting
        $displayErrors = ini_get('display_errors');
        if ($environment === 'production' && $displayErrors) {
            $issues[] = [
                'type' => 'HIGH',
                'category' => 'DISPLAY_ERRORS',
                'message' => 'display_errors is enabled in production',
                'details' => 'This may expose sensitive error information to users'
            ];
        }

        // Check log_errors setting
        $logErrors = ini_get('log_errors');
        if (!$logErrors) {
            $warnings[] = [
                'type' => 'MEDIUM',
                'category' => 'ERROR_LOGGING',
                'message' => 'Error logging is disabled',
                'details' => 'Errors should be logged for monitoring and debugging'
            ];
        }

        return [
            'issues' => $issues,
            'warnings' => $warnings
        ];
    }

    /**
     * Check environment variable consistency
     *
     * @return array Environment consistency warnings
     */
    private static function checkEnvironmentConsistency(): array
    {
        $warnings = [];
        $environment = env('APP_ENV', 'production');

        // Check for common misconfigurations
        if ($environment === 'production') {
            // Check if JWT key is properly set
            $jwtKey = env('JWT_KEY', '');
            if (empty($jwtKey) || strlen($jwtKey) < 32) {
                $warnings[] = [
                    'type' => 'HIGH',
                    'category' => 'JWT_SECURITY',
                    'message' => 'JWT key appears to be weak or missing',
                    'details' => 'Use a strong, randomly generated key for production'
                ];
            }

            // Check database configuration
            $dbHost = env('DB_HOST', 'localhost');
            if ($dbHost === 'localhost' || $dbHost === '127.0.0.1') {
                $warnings[] = [
                    'type' => 'LOW',
                    'category' => 'DATABASE_CONFIG',
                    'message' => 'Database host is localhost in production',
                    'details' => 'Consider using a dedicated database server'
                ];
            }
        }

        return $warnings;
    }

    /**
     * Check PHP security settings
     *
     * @return array PHP security issues
     */
    private static function checkPHPSecuritySettings(): array
    {
        $risks = [];
        $environment = env('APP_ENV', 'production');

        if ($environment === 'production') {
            // Check expose_php setting
            if (ini_get('expose_php')) {
                $risks[] = [
                    'type' => 'LOW',
                    'category' => 'PHP_EXPOSURE',
                    'message' => 'PHP version is exposed in headers',
                    'details' => 'expose_php should be disabled in production'
                ];
            }

            // Check session security
            if (!ini_get('session.cookie_secure') && isset($_SERVER['HTTPS'])) {
                $risks[] = [
                    'type' => 'MEDIUM',
                    'category' => 'SESSION_SECURITY',
                    'message' => 'Session cookies are not marked as secure',
                    'details' => 'session.cookie_secure should be enabled for HTTPS sites'
                ];
            }

            if (!ini_get('session.cookie_httponly')) {
                $risks[] = [
                    'type' => 'MEDIUM',
                    'category' => 'SESSION_SECURITY',
                    'message' => 'Session cookies are accessible via JavaScript',
                    'details' => 'session.cookie_httponly should be enabled'
                ];
            }
        }

        return $risks;
    }

    /**
     * Get configuration value with type checking
     *
     * @param string $setting Setting name
     * @return mixed Configuration value
     */
    private static function getConfigValue(string $setting): mixed
    {
        return match ($setting) {
            'APP_DEBUG' => config('app.debug', false),
            'API_DOCS_ENABLED' => config('api.docs_enabled', false),
            'API_DEBUG_MODE' => config('api.debug_mode', false),
            default => env($setting)
        };
    }

    /**
     * Calculate security score based on issues
     *
     * @param array $issues Critical issues
     * @param array $warnings Warning issues
     * @return float Security score (0-10)
     */
    private static function calculateSecurityScore(array $issues, array $warnings): float
    {
        $score = 10.0;

        foreach ($issues as $issue) {
            $penalty = match ($issue['type']) {
                'CRITICAL' => 3.0,
                'HIGH' => 2.0,
                'MEDIUM' => 1.0,
                'LOW' => 0.5,
                default => 1.0
            };
            $score -= $penalty;
        }

        foreach ($warnings as $warning) {
            $penalty = match ($warning['type']) {
                'HIGH' => 1.0,
                'MEDIUM' => 0.5,
                'LOW' => 0.2,
                default => 0.3
            };
            $score -= $penalty;
        }

        return max(0.0, round($score, 1));
    }

    /**
     * Get security recommendations
     *
     * @param array $issues Critical issues
     * @param array $warnings Warning issues
     * @return array Recommendations
     */
    private static function getRecommendations(array $issues, array $warnings): array
    {
        $recommendations = [];

        if (!empty($issues)) {
            $recommendations[] = 'Address all critical security issues before deploying to production';
        }

        $categories = array_unique(array_merge(
            array_column($issues, 'category'),
            array_column($warnings, 'category')
        ));

        foreach ($categories as $category) {
            $recommendation = match ($category) {
                'DEBUG_EXPOSURE' => 'Set APP_DEBUG=false in production environment',
                'API_DEBUG_EXPOSURE' => 'Disable API debug mode in production',
                'DISPLAY_ERRORS' => 'Set display_errors=Off in PHP configuration',
                'ERROR_LOGGING' => 'Enable error logging for monitoring',
                'JWT_SECURITY' => 'Generate a strong JWT key using: php glueful key:generate',
                'PHP_EXPOSURE' => 'Set expose_php=Off in PHP configuration',
                'SESSION_SECURITY' => 'Configure secure session settings in PHP',
                default => "Review {$category} configuration"
            };

            if (!in_array($recommendation, $recommendations)) {
                $recommendations[] = $recommendation;
            }
        }

        return $recommendations;
    }

    /**
     * Check if current environment should allow debug information
     *
     * @return bool True if debug is allowed
     */
    public static function isDebugAllowed(): bool
    {
        $environment = env('APP_ENV', 'production');
        return in_array($environment, self::DEBUG_ALLOWED_ENVIRONMENTS);
    }

    /**
     * Check if we're in a secure production environment
     *
     * @return bool True if properly configured for production
     */
    public static function isSecureProduction(): bool
    {
        $validation = self::validateProductionSecurity();
        return $validation['passed'] && $validation['is_production'];
    }

    /**
     * Get a summary of current security status
     *
     * @return array Security status summary
     */
    public static function getSecuritySummary(): array
    {
        $validation = self::validateProductionSecurity();

        return [
            'environment' => $validation['environment'],
            'secure' => $validation['passed'],
            'score' => $validation['security_score'],
            'critical_issues_count' => count($validation['critical_issues']),
            'warnings_count' => count($validation['warnings']),
            'top_recommendation' => $validation['recommendations'][0] ?? 'No issues found'
        ];
    }
}
