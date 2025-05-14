<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Scanner;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Code security scanner
 *
 * Performs static analysis on codebase to identify security vulnerabilities
 */
class CodeScanner
{
    /** @var array Scanner configuration */
    private array $config;

    /** @var array Rules to scan for */
    private array $rules;

    /**
     * Constructor
     *
     * @param array $config Scanner configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->loadRules();
    }

    /**
     * Load security rules
     *
     * @return void
     */
    private function loadRules(): void
    {
        // Default rules
        $this->rules = [
            'sql_injection' => [
                'pattern' => '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[\'"][^\'"]*[\'"]\]\s*.*'
                    . '(?:mysql_query|mysqli_query|->query)/i',
                'severity' => 'critical',
                'description' => 'Potential SQL injection found'
            ],
            'xss' => [
                'pattern' => '/echo\s+\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[\'"][^\'"]*[\'"]\]/i',
                'severity' => 'high',
                'description' => 'Potential XSS vulnerability found'
            ],
            'file_inclusion' => [
                'pattern' => '/(?:include|require)(?:_once)?\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i',
                'severity' => 'critical',
                'description' => 'Potential file inclusion vulnerability found'
            ]
        ];

        // Merge with custom rules if defined in config
        if (isset($this->config['custom_rules']) && is_array($this->config['custom_rules'])) {
            $this->rules = array_merge($this->rules, $this->config['custom_rules']);
        }
    }

    /**
     * Scan codebase for security vulnerabilities
     *
     * @return array Scanning results
     */
    public function scan(): array
    {
        $results = [
            'timestamp' => time(),
            'scan_type' => 'code',
            'vulnerabilities' => [],
            'summary' => [
                'files_scanned' => 0,
                'vulnerabilities_found' => 0,
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        // Get files to scan
        $files = $this->getFilesToScan();
        $results['summary']['files_scanned'] = count($files);

        // Scan each file
        foreach ($files as $file) {
            $fileVulnerabilities = $this->scanFile($file);

            if (!empty($fileVulnerabilities)) {
                $results['vulnerabilities'][$file] = $fileVulnerabilities;

                // Update summary counts
                $results['summary']['vulnerabilities_found'] += count($fileVulnerabilities);
                foreach ($fileVulnerabilities as $vulnerability) {
                    $severity = $vulnerability['severity'] ?? 'medium';
                    if (isset($results['summary'][$severity])) {
                        $results['summary'][$severity]++;
                    }
                }
            }
        }

        // Store results in database
        $this->storeResults($results);

        return $results;
    }

    /**
     * Get list of files to scan
     *
     * @return array List of file paths
     */
    private function getFilesToScan(): array
    {
        $files = [];
        $basePath = realpath(__DIR__ . '/../../../../');
        $ignorePatterns = $this->config['ignore_patterns'] ?? ['vendor/*', 'node_modules/*'];

        // Convert ignore patterns to regex
        $ignoreRegex = [];
        foreach ($ignorePatterns as $pattern) {
            $ignoreRegex[] = '#' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '#';
        }

        // Recursively find PHP files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = str_replace($basePath . '/', '', $file->getPathname());

                // Check if file should be ignored
                $ignored = false;
                foreach ($ignoreRegex as $regex) {
                    if (preg_match($regex, $relativePath)) {
                        $ignored = true;
                        break;
                    }
                }

                if (!$ignored) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Scan a single file for vulnerabilities
     *
     * @param string $file File path
     * @return array Vulnerabilities found
     */
    private function scanFile(string $file): array
    {
        $vulnerabilities = [];
        $code = file_get_contents($file);

        if ($code === false) {
            return $vulnerabilities;
        }

        // Apply each rule
        foreach ($this->rules as $ruleId => $rule) {
            if (preg_match_all($rule['pattern'], $code, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    // Find line number
                    $line = substr_count(substr($code, 0, (int)$match[1]), "\n") + 1;

                    $vulnerabilities[] = [
                        'rule_id' => $ruleId,
                        'description' => $rule['description'],
                        'severity' => $rule['severity'],
                        'line' => $line,
                        'code' => trim($match[0])
                    ];
                }
            }
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
                'type' => 'code',
                'timestamp' => date('Y-m-d H:i:s', $results['timestamp']),
                'files_scanned' => $results['summary']['files_scanned'],
                'vulnerabilities_found' => $results['summary']['vulnerabilities_found']
            ]);

            // Insert individual vulnerabilities
            foreach ($results['vulnerabilities'] as $file => $fileVulnerabilities) {
                foreach ($fileVulnerabilities as $vulnerability) {
                    $db->insert('security_vulnerabilities', [
                        'scan_id' => $scanId,
                        'file_path' => $file,
                        'rule_id' => $vulnerability['rule_id'],
                        'description' => $vulnerability['description'],
                        'severity' => $vulnerability['severity'],
                        'line' => $vulnerability['line'],
                        'code' => $vulnerability['code'],
                        'status' => 'new'
                    ]);
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to store code scan results: " . $e->getMessage());
        }
    }
}
