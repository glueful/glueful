<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager;

/**
 * Data Classification System
 *
 * Identifies and classifies sensitive data including PII (Personally Identifiable Information)
 * and PHI (Protected Health Information), enabling data tagging and flow tracking.
 */
class DataClassifier
{
    /** @var array Configured sensitive data patterns */
    private array $dataPatterns;

    /** @var array Detected sensitive data in the current context */
    private array $detectedData = [];

    /** @var array Data flow paths tracked during processing */
    private array $dataFlowPaths = [];

    /**
     * Constructor
     *
     * @param array $customPatterns Additional patterns to use for classification
     */
    public function __construct(array $customPatterns = [])
    {
        // Load default patterns first
        $this->dataPatterns = $this->getDefaultPatterns();

        // Merge with custom patterns if provided
        if (!empty($customPatterns)) {
            $this->dataPatterns = array_merge($this->dataPatterns, $customPatterns);
        }
    }

    /**
     * Analyzes data to identify and classify sensitive information
     *
     * @param mixed $data The data to analyze
     * @param string $context The context where this data is being processed
     * @return array Classification results
     */
    public function classifyData($data, string $context = 'general'): array
    {
        $this->detectedData = [];

        if (is_array($data) || is_object($data)) {
            $this->processStructuredData($data, $context);
        } elseif (is_string($data)) {
            $this->processStringData($data, $context);
        }

        return $this->detectedData;
    }

    /**
     * Processes structured data (arrays and objects)
     *
     * @param mixed $data Array or object to process
     * @param string $context The processing context
     * @param string $path Current data path for tracking
     */
    private function processStructuredData($data, string $context, string $path = ''): void
    {
        $dataArray = is_object($data) ? get_object_vars($data) : $data;

        foreach ($dataArray as $key => $value) {
            $currentPath = $path ? "$path.$key" : $key;

            // Check if the key itself indicates sensitive data
            $this->checkKeyForSensitiveData($key, $value, $currentPath, $context);

            // Recursively process nested structures
            if (is_array($value) || is_object($value)) {
                $this->processStructuredData($value, $context, $currentPath);
            } elseif (is_string($value)) {
                $this->processStringData($value, $context, $currentPath);
            }
        }
    }

    /**
     * Processes string data to detect sensitive information
     *
     * @param string $data String data to process
     * @param string $context The processing context
     * @param string $path Current data path for tracking
     */
    private function processStringData(string $data, string $context, string $path = ''): void
    {
        foreach ($this->dataPatterns as $category => $patterns) {
            foreach ($patterns as $patternName => $pattern) {
                if (preg_match($pattern, $data)) {
                    $this->addDetectedData($category, $patternName, $path, $context);
                }
            }
        }
    }

    /**
     * Checks if a key name suggests sensitive data
     *
     * @param string $key The key name to check
     * @param mixed $value The associated value
     * @param string $path Current data path
     * @param string $context The processing context
     */
    private function checkKeyForSensitiveData(string $key, $value, string $path, string $context): void
    {
        $sensitiveKeyPatterns = [
            'PII' => [
                'email' => '/email|mail/i',
                'ssn' => '/ssn|social.*security|tax.?id/i',
                'dob' => '/dob|birth|birthday|date.?of.?birth/i',
                'password' => '/password|passwd|pass/i',
                'address' => '/address|street|city|state|zip|postal/i',
                'phone' => '/phone|mobile|cell/i',
                'name' => '/first.?name|last.?name|full.?name|name/i',
            ],
            'PHI' => [
                'medical' => '/medical|health|diagnosis|treatment|condition/i',
                'insurance' => '/insurance|policy/i',
                'provider' => '/provider|doctor|physician|hospital/i',
            ],
            'Financial' => [
                'card' => '/credit.?card|debit.?card|card.?number/i',
                'account' => '/account.?number|bank.?account/i',
                'payment' => '/payment|transaction/i',
            ]
        ];

        foreach ($sensitiveKeyPatterns as $category => $patterns) {
            foreach ($patterns as $patternName => $pattern) {
                if (preg_match($pattern, strtolower($key))) {
                    $this->addDetectedData($category, $patternName, $path, $context);
                }
            }
        }
    }

    /**
     * Records detected sensitive data
     *
     * @param string $category Data category (PII, PHI, etc.)
     * @param string $type Type of sensitive data
     * @param string $path Data path where detected
     * @param string $context Processing context
     */
    private function addDetectedData(string $category, string $type, string $path, string $context): void
    {
        // Add to detected data collection
        $this->detectedData[] = [
            'category' => $category,
            'type' => $type,
            'path' => $path,
            'context' => $context,
            'detected_at' => date('Y-m-d H:i:s'),
        ];

        // Track the data flow
        $this->trackDataFlow($category, $type, $path, $context);
    }

    /**
     * Tracks data flow for the detected sensitive information
     *
     * @param string $category Data category
     * @param string $type Type of sensitive data
     * @param string $path Data path
     * @param string $context Processing context
     */
    private function trackDataFlow(string $category, string $type, string $path, string $context): void
    {
        $flowId = md5($category . $type . $path);

        $this->dataFlowPaths[$flowId] = [
            'category' => $category,
            'type' => $type,
            'path' => $path,
            'context' => $context,
            'first_seen' => date('Y-m-d H:i:s'),
            'last_seen' => date('Y-m-d H:i:s'),
            'access_count' => 1,
        ];
    }

    /**
     * Retrieves the tracked data flow information
     *
     * @return array Data flow tracking information
     */
    public function getDataFlowTracking(): array
    {
        return $this->dataFlowPaths;
    }

    /**
     * Tags data with appropriate sensitivity classifications
     *
     * @param mixed $data Data to be tagged
     * @param array $classifications Classification results from classifyData()
     * @return array Tagged data with metadata
     */
    public function tagData($data, array $classifications): array
    {
        $metadata = [
            'has_sensitive_data' => !empty($classifications),
            'sensitivity_level' => $this->calculateSensitivityLevel($classifications),
            'classifications' => $classifications,
            'tagged_at' => date('Y-m-d H:i:s'),
        ];

        return [
            'data' => $data,
            'metadata' => $metadata,
        ];
    }

    /**
     * Calculates overall sensitivity level based on detected data
     *
     * @param array $classifications Classification results
     * @return string Sensitivity level (low, medium, high, critical)
     */
    private function calculateSensitivityLevel(array $classifications): string
    {
        if (empty($classifications)) {
            return 'none';
        }

        $categoryWeights = [
            'PII' => 3,
            'PHI' => 4,
            'Financial' => 4,
            'General' => 1,
        ];

        $totalWeight = 0;

        foreach ($classifications as $item) {
            $category = $item['category'];
            $weight = $categoryWeights[$category] ?? 1;
            $totalWeight += $weight;
        }

        if ($totalWeight >= 10) {
            return 'critical';
        } elseif ($totalWeight >= 6) {
            return 'high';
        } elseif ($totalWeight >= 3) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Returns default regex patterns for identifying sensitive data
     *
     * @return array Default patterns by category
     */
    private function getDefaultPatterns(): array
    {
        return [
            'PII' => [
                'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
                'ssn' => '/\b(?!000|666|9\d{2})([0-6]\d{2}|7([0-6]\d|7[012]))([-]?)(?!00)\d\d\3(?!0000)\d{4}\b/',
                'credit_card' => '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|'
                    . '3(?:0[0-5]|[68][0-9])[0-9]{11}|6(?:011|5[0-9]{2})[0-9]{12}|'
                    . '(?:2131|1800|35\d{3})\d{11})\b/',
                'phone' => '/\b(?:\+?1[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})\b/',
                'zipcode' => '/\b[0-9]{5}(?:-[0-9]{4})?\b/',
                'ip_address' => '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}'
                    . '(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/',
            ],
            'PHI' => [
                'medical_record_number' => '/\bMR[0-9]{6,}\b/i',
                'health_plan_beneficiary' => '/\b[0-9]{3}[\-][0-9]{2}[\-][0-9]{4}\b/',
                'healthcare_identifier' => '/\b[A-Z]{3}[0-9]{6}\b/i',
            ],
            'Financial' => [
                'bank_account' => '/\b[0-9]{8,17}\b/',
                'routing_number' => '/\b[0-9]{9}\b/',
            ],
        ];
    }

    /**
     * Allows registration of custom data patterns
     *
     * @param string $category Category for the pattern (e.g., PII, PHI)
     * @param string $name Pattern name
     * @param string $pattern Regex pattern to match
     * @return void
     */
    public function registerCustomPattern(string $category, string $name, string $pattern): void
    {
        $this->dataPatterns[$category][$name] = $pattern;
    }
}
