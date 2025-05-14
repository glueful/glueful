<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Scanner;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Dependency security scanner
 *
 * Scans composer and npm dependencies for known vulnerabilities
 */
class DependencyScanner
{
    /** @var array Scanner configuration */
    private array $config;

    /**
     * Constructor
     *
     * @param array $config Scanner configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Scan dependencies for security vulnerabilities
     *
     * @return array Scanning results
     */
    public function scan(): array
    {
        $results = [
            'timestamp' => time(),
            'scan_type' => 'dependency',
            'vulnerabilities' => [],
            'summary' => [
                'packages_scanned' => 0,
                'vulnerabilities_found' => 0,
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        // Scan composer packages if enabled
        if ($this->config['composer_packages'] ?? true) {
            $composerResults = $this->scanComposerPackages();
            $results['vulnerabilities']['composer'] = $composerResults['vulnerabilities'];
            $results['summary']['packages_scanned'] += $composerResults['summary']['packages_scanned'];
            $results['summary']['vulnerabilities_found'] += $composerResults['summary']['vulnerabilities_found'];

            // Update severity counts
            foreach (['critical', 'high', 'medium', 'low'] as $severity) {
                $results['summary'][$severity] += $composerResults['summary'][$severity] ?? 0;
            }
        }

        // Scan npm packages if enabled
        if ($this->config['npm_packages'] ?? true) {
            $npmResults = $this->scanNpmPackages();
            $results['vulnerabilities']['npm'] = $npmResults['vulnerabilities'];
            $results['summary']['packages_scanned'] += $npmResults['summary']['packages_scanned'];
            $results['summary']['vulnerabilities_found'] += $npmResults['summary']['vulnerabilities_found'];

            // Update severity counts
            foreach (['critical', 'high', 'medium', 'low'] as $severity) {
                $results['summary'][$severity] += $npmResults['summary'][$severity] ?? 0;
            }
        }

        // Store results in database
        $this->storeResults($results);

        return $results;
    }

    /**
     * Scan composer packages
     *
     * @return array Results for composer packages
     */
    private function scanComposerPackages(): array
    {
        $results = [
            'vulnerabilities' => [],
            'summary' => [
                'packages_scanned' => 0,
                'vulnerabilities_found' => 0,
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        // Get composer.lock file
        $composerLockPath = realpath(__DIR__ . '/../../../../composer.lock');
        if (!file_exists($composerLockPath)) {
            return $results;
        }

        // Parse composer.lock
        $composerLock = json_decode(file_get_contents($composerLockPath), true);
        if (!$composerLock || !isset($composerLock['packages'])) {
            return $results;
        }

        $results['summary']['packages_scanned'] = count($composerLock['packages']);

        // Check each package against security advisories
        foreach ($composerLock['packages'] as $package) {
            $packageName = $package['name'] ?? 'unknown';
            $packageVersion = $package['version'] ?? 'unknown';

            // Check for vulnerabilities using the API
            $vulnerabilities = $this->checkPackageVulnerabilities('composer', $packageName, $packageVersion);

            if (!empty($vulnerabilities)) {
                $results['vulnerabilities'][$packageName] = [
                    'version' => $packageVersion,
                    'vulnerabilities' => $vulnerabilities
                ];

                // Update summary counts
                $results['summary']['vulnerabilities_found'] += count($vulnerabilities);
                foreach ($vulnerabilities as $vulnerability) {
                    $severity = $vulnerability['severity'] ?? 'medium';
                    if (isset($results['summary'][$severity])) {
                        $results['summary'][$severity]++;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Scan npm packages
     *
     * @return array Results for npm packages
     */
    private function scanNpmPackages(): array
    {
        $results = [
            'vulnerabilities' => [],
            'summary' => [
                'packages_scanned' => 0,
                'vulnerabilities_found' => 0,
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        // Get package-lock.json file
        $packageLockPath = realpath(__DIR__ . '/../../../../package-lock.json');
        if (!file_exists($packageLockPath)) {
            return $results;
        }

        // Parse package-lock.json
        $packageLock = json_decode(file_get_contents($packageLockPath), true);
        if (!$packageLock || !isset($packageLock['packages'])) {
            return $results;
        }

        $results['summary']['packages_scanned'] = count($packageLock['packages']);

        // Check each package against security advisories
        foreach ($packageLock['packages'] as $packageName => $package) {
            // Skip root package
            if ($packageName === '') {
                continue;
            }

            $packageName = ltrim($packageName, '/');
            $packageVersion = $package['version'] ?? 'unknown';

            // Check for vulnerabilities using the API
            $vulnerabilities = $this->checkPackageVulnerabilities('npm', $packageName, $packageVersion);

            if (!empty($vulnerabilities)) {
                $results['vulnerabilities'][$packageName] = [
                    'version' => $packageVersion,
                    'vulnerabilities' => $vulnerabilities
                ];

                // Update summary counts
                $results['summary']['vulnerabilities_found'] += count($vulnerabilities);
                foreach ($vulnerabilities as $vulnerability) {
                    $severity = $vulnerability['severity'] ?? 'medium';
                    if (isset($results['summary'][$severity])) {
                        $results['summary'][$severity]++;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check package for known vulnerabilities
     *
     * @param string $type Package type (composer or npm)
     * @param string $name Package name
     * @param string $version Package version
     * @return array List of vulnerabilities
     */
    private function checkPackageVulnerabilities(string $type, string $name, string $version): array
    {
        $vulnerabilities = [];

        // Use security advisory database
        // In a real implementation, this would query an API like:
        // - https://api.github.com/advisories
        // - https://api.osv.dev/v1/query

        // For this example, we'll simulate some results
        if ($type === 'composer' && $name === 'example/vulnerable-package' && version_compare($version, '2.0.0', '<')) {
            $vulnerabilities[] = [
                'id' => 'CVE-2023-12345',
                'title' => 'SQL Injection in example/vulnerable-package',
                'severity' => 'high',
                'description' => 'SQL injection vulnerability in versions prior to 2.0.0',
                'affected_versions' => '<2.0.0',
                'fixed_version' => '2.0.0',
                'references' => ['https://cve.mitre.org/cgi-bin/cvename.cgi?name=CVE-2023-12345']
            ];
        }

        // Example check for NPM package
        if ($type === 'npm' && $name === 'example-vulnerable-package' && version_compare($version, '1.5.0', '<')) {
            $vulnerabilities[] = [
                'id' => 'CVE-2023-67890',
                'title' => 'Path Traversal in example-vulnerable-package',
                'severity' => 'critical',
                'description' => 'Path traversal vulnerability allowing file access outside intended directory',
                'affected_versions' => '<1.5.0',
                'fixed_version' => '1.5.0',
                'references' => ['https://cve.mitre.org/cgi-bin/cvename.cgi?name=CVE-2023-67890']
            ];
        }

        return $vulnerabilities;
    }

    /**
     * Store scan results in database
     *
     * @param array $results Scan results
     * @return void
     */
    private function storeResults(array $results): void
    {
        try {
            $connection = new Connection();
            $db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Insert scan record
            $scanId = $db->insert('security_scans', [
                'type' => 'dependency',
                'timestamp' => date('Y-m-d H:i:s', $results['timestamp']),
                'packages_scanned' => $results['summary']['packages_scanned'],
                'vulnerabilities_found' => $results['summary']['vulnerabilities_found']
            ]);

            // Insert individual vulnerabilities
            foreach ($results['vulnerabilities'] as $packageType => $packages) {
                foreach ($packages as $packageName => $packageData) {
                    foreach ($packageData['vulnerabilities'] as $vulnerability) {
                        $db->insert('security_vulnerabilities', [
                            'scan_id' => $scanId,
                            'package_type' => $packageType,
                            'package_name' => $packageName,
                            'package_version' => $packageData['version'],
                            'vulnerability_id' => $vulnerability['id'],
                            'title' => $vulnerability['title'],
                            'description' => $vulnerability['description'],
                            'severity' => $vulnerability['severity'],
                            'fixed_version' => $vulnerability['fixed_version'] ?? null,
                            'status' => 'new'
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to store dependency scan results: " . $e->getMessage());
        }
    }
}
