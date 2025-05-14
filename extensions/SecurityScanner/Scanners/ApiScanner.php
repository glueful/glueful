<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Scanner;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * API security scanner
 *
 * Tests API endpoints for security vulnerabilities
 */
class ApiScanner
{
    /** @var array Scanner configuration */
    private array $config;

    /** @var array Security test cases */
    private array $testCases;

    /**
     * Constructor
     *
     * @param array $config Scanner configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->loadTestCases();
    }

    /**
     * Load security test cases
     *
     * @return void
     */
    private function loadTestCases(): void
    {
        // Default test cases
        $this->testCases = [
            'sql_injection' => [
                'payloads' => ["' OR '1'='1", "'; DROP TABLE users; --"],
                'severity' => 'critical',
                'description' => 'SQL injection attempt'
            ],
            'xss' => [
                'payloads' => ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>'],
                'severity' => 'high',
                'description' => 'Cross-site scripting attempt'
            ],
            'auth_bypass' => [
                'payloads' => ['admin:admin', 'OR 1=1'],
                'severity' => 'critical',
                'description' => 'Authentication bypass attempt'
            ]
        ];

        // Merge with custom test cases if defined in config
        if (isset($this->config['custom_tests']) && is_array($this->config['custom_tests'])) {
            $this->testCases = array_merge($this->testCases, $this->config['custom_tests']);
        }
    }

    /**
     * Scan API endpoints for security vulnerabilities
     *
     * @return array Scanning results
     */
    public function scan(): array
    {
        $results = [
            'timestamp' => time(),
            'scan_type' => 'api',
            'vulnerabilities' => [],
            'summary' => [
                'endpoints_scanned' => 0,
                'tests_performed' => 0,
                'vulnerabilities_found' => 0,
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ]
        ];

        // Get endpoints to test
        $endpoints = $this->discoverEndpoints();
        $results['summary']['endpoints_scanned'] = count($endpoints);

        // Test each endpoint
        foreach ($endpoints as $endpoint) {
            $endpointVulnerabilities = $this->testEndpoint($endpoint);
            $results['summary']['tests_performed'] += count($this->testCases) * count($endpoint['methods']);

            if (!empty($endpointVulnerabilities)) {
                $results['vulnerabilities'][$endpoint['path']] = $endpointVulnerabilities;

                // Update summary counts
                $results['summary']['vulnerabilities_found'] += count($endpointVulnerabilities);
                foreach ($endpointVulnerabilities as $vulnerability) {
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
     * Discover API endpoints
     *
     * @return array List of endpoints
     */
    private function discoverEndpoints(): array
    {
        $endpoints = [];

        // If endpoints are configured explicitly
        if ($this->config['endpoints'] !== 'auto-discover') {
            foreach ($this->config['endpoints'] as $path => $methods) {
                $endpoints[] = [
                    'path' => $path,
                    'methods' => $methods
                ];
            }
            return $endpoints;
        }

        // Otherwise auto-discover from routes
        try {
            $routes = \Glueful\Http\Router::getRoutes();

            foreach ($routes as $routeName => $route) {
                $path = $route->getPath();
                // Skip non-API routes
                if (!str_starts_with($path, '/api/')) {
                    continue;
                }

                $endpoints[] = [
                    'path' => $path,
                    'methods' => $route->getMethods()
                ];
            }
        } catch (\Exception $e) {
            error_log("Failed to auto-discover API endpoints: " . $e->getMessage());

            // Fallback to some common API endpoints
            $endpoints = [
                ['path' => '/api/users', 'methods' => ['GET', 'POST']],
                ['path' => '/api/users/{id}', 'methods' => ['GET', 'PUT', 'DELETE']],
                ['path' => '/api/auth/login', 'methods' => ['POST']]
            ];
        }

        return $endpoints;
    }

    /**
     * Test an endpoint for vulnerabilities
     *
     * @param array $endpoint Endpoint information
     * @return array Vulnerabilities found
     */
    private function testEndpoint(array $endpoint): array
    {
        $vulnerabilities = [];
        $path = $endpoint['path'];
        $methods = $endpoint['methods'];
        $testMethods = $this->config['test_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE'];

        // Only test configured methods
        $methods = array_intersect($methods, $testMethods);

        // Replace path parameters with values
        $testPath = preg_replace('/{([^}]+)}/', '1', $path);
        $baseUrl = $this->getBaseUrl();
        $fullUrl = $baseUrl . $testPath;

        // Test each method
        foreach ($methods as $method) {
            // Test each test case
            foreach ($this->testCases as $testId => $testCase) {
                // Test each payload
                foreach ($testCase['payloads'] as $payload) {
                    $vulnerable = $this->testEndpointWithPayload($method, $fullUrl, $payload, $testCase);

                    if ($vulnerable) {
                        $vulnerabilities[] = [
                            'test_id' => $testId,
                            'method' => $method,
                            'payload' => $payload,
                            'description' => $testCase['description'],
                            'severity' => $testCase['severity']
                        ];

                        // Break after finding a vulnerability for this test case
                        break;
                    }
                }
            }
        }

        return $vulnerabilities;
    }

    /**
     * Test an endpoint with a specific payload
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param string $payload Test payload
     * @param array $testCase Test case information
     * @return bool Whether the endpoint is vulnerable
     */
    private function testEndpointWithPayload(string $method, string $url, string $payload, array $testCase): bool
    {
        // In a real implementation, this would actually send requests to the API
        // and analyze responses for security issues. For this example, we'll
        // simulate the test without actually sending requests.

        // Simulate test logic - in a real scanner this would be much more sophisticated
        return false;
    }

    /**
     * Get base URL for API testing
     *
     * @return string Base URL
     */
    private function getBaseUrl(): string
    {
        // Get base URL from config or use default
        return $this->config['base_url'] ?? 'http://localhost:8000';
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
                'type' => 'api',
                'timestamp' => date('Y-m-d H:i:s', $results['timestamp']),
                'endpoints_scanned' => $results['summary']['endpoints_scanned'],
                'tests_performed' => $results['summary']['tests_performed'],
                'vulnerabilities_found' => $results['summary']['vulnerabilities_found']
            ]);

            // Insert individual vulnerabilities
            foreach ($results['vulnerabilities'] as $endpoint => $endpointVulnerabilities) {
                foreach ($endpointVulnerabilities as $vulnerability) {
                    $db->insert('security_vulnerabilities', [
                        'scan_id' => $scanId,
                        'endpoint' => $endpoint,
                        'method' => $vulnerability['method'],
                        'test_id' => $vulnerability['test_id'],
                        'payload' => $vulnerability['payload'],
                        'description' => $vulnerability['description'],
                        'severity' => $vulnerability['severity'],
                        'status' => 'new'
                    ]);
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to store API scan results: " . $e->getMessage());
        }
    }
}
