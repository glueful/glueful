<?php

namespace Tests\Unit\Logging\Mocks;

/**
 * Mock class for log sanitization in tests
 */
class MockLogSanitizer
{
    /**
     * List of sensitive field names to sanitize
     */
    private static array $sensitiveFields = [
        'password', 'secret', 'key', 'token', 'credential',
        'auth', 'credit_card', 'cc', 'cvv', 'ssn', 'social_security'
    ];

    /**
     * Sanitize context data by removing sensitive values
     *
     * @param array $context Context data to sanitize
     * @return array Sanitized context data
     */
    public static function sanitizeContext(array $context): array
    {
        // Create a copy of the context to avoid modifying the original
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Check if it's a sensitive field
            if (self::isSensitiveField($key)) {
                // If it's a string, redact it
                if (is_string($value)) {
                    // Credit card pattern
                    if (preg_match('/^\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}$/', $value)) {
                        $sanitized[$key] = 'XXXX-XXXX-XXXX-' . substr($value, -4);
                    } else {
                        $sanitized[$key] = '[REDACTED]';
                    }
                } else {
                    $sanitized[$key] = '[REDACTED]';
                }
            }
            // If array, recursively sanitize its contents
            elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeContext($value);
            }
            // If a JSON string, try to sanitize its contents
            elseif (is_string($value) && self::isJsonString($value)) {
                $decodedJson = json_decode($value, true);
                if ($decodedJson) {
                    $sanitizedJson = self::sanitizeContext($decodedJson);
                    $sanitized[$key] = json_encode($sanitizedJson);
                } else {
                    $sanitized[$key] = $value;
                }
            }
            // Otherwise, keep as is
            else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a field name is sensitive
     *
     * @param string $fieldName Field name to check
     * @return bool Whether the field is sensitive
     */
    private static function isSensitiveField(string $fieldName): bool
    {
        $fieldName = strtolower($fieldName);
        foreach (self::$sensitiveFields as $sensitiveField) {
            if (strpos($fieldName, $sensitiveField) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a string is a valid JSON string
     *
     * @param string $string String to check
     * @return bool Whether the string is valid JSON
     */
    private static function isJsonString(string $string): bool
    {
        $string = trim($string);
        // Simple check to avoid unnecessary json_decode attempts
        if (!str_starts_with($string, '{') && !str_starts_with($string, '[')) {
            return false;
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Enrich log context with additional system information
     *
     * @param array $context Original context
     * @return array Enriched context
     */
    public static function enrichContext(array $context): array
    {
        // Add request info if in web context
        if (isset($_SERVER['REQUEST_URI'])) {
            $context['request_uri'] = $_SERVER['REQUEST_URI'];
        }

        // Add memory usage
        $context['memory_usage'] = memory_get_usage(true);

        // Add hostname
        $context['hostname'] = gethostname();

        return $context;
    }
}
