<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Services\HealthService;
use Glueful\DI\Interfaces\ContainerInterface;

/**
 * System Check Command
 *
 * Validates framework installation and configuration
 * for developers using the framework.
 */
class SystemCheckCommand extends Command
{
    /** @var ContainerInterface DI Container */
    protected ContainerInterface $container;

    /**
     * Constructor
     *
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? container();
    }

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


    public function checkPhpVersion(): array
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

    public function checkPhpExtensions(): array
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

    public function checkPermissions(bool $fix = false): array
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

    public function checkConfiguration(bool $production): array
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

    public function checkSecurity(bool $production): array
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

    public function checkDatabase(): array
    {
        // Get HealthService from DI container
        $healthService = $this->container->get(HealthService::class);
        $healthResult = $healthService->checkDatabase();
        return $healthService->convertToSystemCheckFormat($healthResult);
    }
}
