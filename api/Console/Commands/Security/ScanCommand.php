<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\Commands\Security\BaseSecurityCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security Scan Command
 * - Comprehensive security vulnerability scanning
 * - File system security analysis
 * - Code security pattern detection
 * - Dependency vulnerability assessment
 * - Real-time threat detection
 * @package Glueful\Console\Commands\Security
 */
#[AsCommand(
    name: 'security:scan',
    description: 'Scan for security vulnerabilities'
)]
class ScanCommand extends BaseSecurityCommand
{
    protected function configure(): void
    {
        $this->setDescription('Scan for security vulnerabilities')
             ->setHelp('This command performs comprehensive security vulnerability scanning ' .
                      'including code analysis, dependency checks, and file system security.')
             ->addOption(
                 'deep',
                 'd',
                 InputOption::VALUE_NONE,
                 'Perform deep security scan (slower but more thorough)'
             )
             ->addOption(
                 'fix',
                 'f',
                 InputOption::VALUE_NONE,
                 'Attempt to automatically fix detected vulnerabilities'
             )
             ->addOption(
                 'output',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Output scan results to file'
             )
             ->addOption(
                 'format',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Output format (json, html, txt)',
                 'txt'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deep = $input->getOption('deep');
        $fix = $input->getOption('fix');
        $outputFile = $input->getOption('output');
        $format = $input->getOption('format');

        $this->info('🔍 Security Vulnerability Scan');
        $this->line('');

        if ($deep) {
            $this->info('Performing deep security scan...');
        } else {
            $this->info('Performing standard security scan...');
        }

        try {
            // Determine scan types from options (following original pattern)
            $scanTypes = ['code', 'dependency', 'config'];
            if ($deep) {
                // Deep scan includes additional security checks
                $scanTypes[] = 'runtime';
                $scanTypes[] = 'network';
            }

            // Run the vulnerability scan using VulnerabilityScanner
            $scanner = $this->getService(\Glueful\Security\VulnerabilityScanner::class);
            $results = $scanner->scan($scanTypes);

            // Display results using original displayScanResults method
            $this->displayScanResults($results);

            // Save results to file if requested
            if ($outputFile) {
                $this->saveResultsToFile($results, $outputFile, $format);
            }

            // Determine exit code based on vulnerabilities found (following original pattern)
            $critical = $results['summary']['critical'];
            $high = $results['summary']['high'];

            if ($critical > 0) {
                $this->error("Critical vulnerabilities found: {$critical}");

                if ($fix) {
                    $this->info('Attempting to apply automatic fixes...');
                    $fixResults = $this->applySecurityFixes($results);
                    if (!empty($fixResults['fixed'])) {
                        $this->info("Applied {$fixResults['fixed']} automatic fixes");
                    }
                }

                return self::FAILURE;
            } elseif ($high > 0) {
                $this->warning("High severity vulnerabilities found: {$high}");

                if ($fix) {
                    $this->info('Attempting to apply automatic fixes...');
                    $fixResults = $this->applySecurityFixes($results);
                    if (!empty($fixResults['fixed'])) {
                        $this->info("Applied {$fixResults['fixed']} automatic fixes");
                    }
                }

                return self::SUCCESS; // Still success, but with warning
            }

            $this->success('Security scan completed - no critical vulnerabilities found');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Security scan failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function displayScanResults(array $result): void
    {
        $this->line('');
        $this->info('Scan Results:');
        $this->line('=============');

        // Summary table
        $summary = [
            ['Files Scanned', $result['files_scanned'] ?? 0],
            ['Vulnerabilities Found', $result['vulnerabilities_found'] ?? 0],
            ['Risk Level', $result['risk_level'] ?? 'Unknown'],
            ['Scan Duration', isset($result['scan_duration']) ? $result['scan_duration'] . 's' : 'Unknown']
        ];

        $this->table(['Metric', 'Value'], $summary);

        // Show vulnerabilities if any
        if (!empty($result['vulnerabilities']) && is_array($result['vulnerabilities'])) {
            $this->line('');
            $this->info('Vulnerabilities Found:');
            $this->line('');

            foreach ($result['vulnerabilities'] as $i => $vuln) {
                $severity = $vuln['severity'] ?? 'Unknown';
                $type = $vuln['type'] ?? 'Unknown';
                $file = $vuln['file'] ?? 'Unknown';
                $line = $vuln['line'] ?? 'Unknown';
                $description = $vuln['description'] ?? 'No description';

                $severityIcon = match (strtolower($severity)) {
                    'critical' => '🔴',
                    'high' => '🟠',
                    'medium' => '🟡',
                    'low' => '🟢',
                    default => '⚪'
                };

                $this->line(($i + 1) . ". {$severityIcon} {$severity} - {$type}");
                $this->line("   File: {$file}:{$line}");
                $this->line("   Description: {$description}");

                if (!empty($vuln['solution'])) {
                    $this->line("   Solution: {$vuln['solution']}");
                }

                $this->line('');
            }
        }

        // Show categories if available
        if (!empty($result['categories'])) {
            $this->line('');
            $this->info('Vulnerability Categories:');
            foreach ($result['categories'] as $category => $count) {
                $this->line("  • {$category}: {$count}");
            }
        }
    }

    private function saveResultsToFile(array $results, string $outputFile, string $format): void
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = match ($format) {
            'json' => json_encode($results, JSON_PRETTY_PRINT),
            'html' => $this->generateHtmlReport($results),
            default => $this->generateTextReport($results)
        };

        file_put_contents($outputFile, $content);
        $this->success("Scan results saved to: {$outputFile}");
    }

    private function generateHtmlReport(array $results): string
    {
        $html = "<html><head><title>Security Scan Results</title></head><body>";
        $html .= "<h1>Security Vulnerability Scan Results</h1>";
        $html .= "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";
        $html .= "<h2>Summary</h2>";
        $html .= "<p>Files Scanned: " . ($results['files_scanned'] ?? 0) . "</p>";
        $html .= "<p>Vulnerabilities Found: " . ($results['vulnerabilities_found'] ?? 0) . "</p>";
        $html .= "<p>Risk Level: " . ($results['risk_level'] ?? 'Unknown') . "</p>";

        if (!empty($results['vulnerabilities'])) {
            $html .= "<h2>Vulnerabilities</h2>";
            foreach ($results['vulnerabilities'] as $vuln) {
                $html .= "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>";
                $html .= "<h3>" . ($vuln['severity'] ?? 'Unknown') . " - " . ($vuln['type'] ?? 'Unknown') . "</h3>";
                $html .= "<p><strong>File:</strong> " . ($vuln['file'] ?? 'Unknown') . ":" .
                    ($vuln['line'] ?? 'Unknown') . "</p>";
                $html .= "<p><strong>Description:</strong> " .
                    ($vuln['description'] ?? 'No description') . "</p>";
                if (!empty($vuln['solution'])) {
                    $html .= "<p><strong>Solution:</strong> " . $vuln['solution'] . "</p>";
                }
                $html .= "</div>";
            }
        }

        $html .= "</body></html>";
        return $html;
    }

    private function generateTextReport(array $results): string
    {
        $report = "SECURITY VULNERABILITY SCAN RESULTS\n";
        $report .= "=====================================\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $report .= "Files Scanned: " . ($results['files_scanned'] ?? 0) . "\n";
        $report .= "Vulnerabilities Found: " . ($results['vulnerabilities_found'] ?? 0) . "\n";
        $report .= "Risk Level: " . ($results['risk_level'] ?? 'Unknown') . "\n\n";

        if (!empty($results['vulnerabilities'])) {
            $report .= "VULNERABILITIES:\n";
            $report .= "===============\n\n";
            foreach ($results['vulnerabilities'] as $i => $vuln) {
                $report .= ($i + 1) . ". " . ($vuln['severity'] ?? 'Unknown') . " - " .
                    ($vuln['type'] ?? 'Unknown') . "\n";
                $report .= "   File: " . ($vuln['file'] ?? 'Unknown') . ":" .
                    ($vuln['line'] ?? 'Unknown') . "\n";
                $report .= "   Description: " . ($vuln['description'] ?? 'No description') . "\n";
                if (!empty($vuln['solution'])) {
                    $report .= "   Solution: " . $vuln['solution'] . "\n";
                }
                $report .= "\n";
            }
        }

        return $report;
    }

    private function applySecurityFixes(array $results): array
    {
        $fixResults = ['fixed' => 0, 'failed' => 0, 'details' => []];

        if (empty($results['vulnerabilities'])) {
            return $fixResults;
        }

        foreach ($results['vulnerabilities'] as $vuln) {
            // Only attempt to fix certain types of vulnerabilities
            $fixableTypes = ['permission_issue', 'config_issue', 'file_permission'];

            if (!in_array($vuln['type'] ?? '', $fixableTypes)) {
                continue;
            }

            try {
                $fixed = $this->applySingleFix($vuln);
                if ($fixed) {
                    $fixResults['fixed']++;
                    $fixResults['details'][] = "Fixed: " . ($vuln['description'] ?? 'Unknown issue');
                } else {
                    $fixResults['failed']++;
                    $fixResults['details'][] = "Failed to fix: " .
                        ($vuln['description'] ?? 'Unknown issue');
                }
            } catch (\Exception $e) {
                $fixResults['failed']++;
                $fixResults['details'][] = "Error fixing " .
                    ($vuln['description'] ?? 'Unknown issue') . ": " . $e->getMessage();
            }
        }

        return $fixResults;
    }

    private function applySingleFix(array $vulnerability): bool
    {
        $type = $vulnerability['type'] ?? '';
        $file = $vulnerability['file'] ?? '';

        switch ($type) {
            case 'permission_issue':
                if ($file && file_exists($file)) {
                    return chmod($file, 0644);
                }
                break;

            case 'config_issue':
                // Log the issue for manual review
                $this->warning("Config issue requires manual review: " .
                    ($vulnerability['description'] ?? ''));
                return false;

            case 'file_permission':
                if ($file && file_exists($file)) {
                    return chmod($file, 0644);
                }
                break;
        }

        return false;
    }
}
