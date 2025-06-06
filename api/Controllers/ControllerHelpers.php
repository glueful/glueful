<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Helpers\Utils;

/**
 * ControllerHelpers
 *
 * Static utility class providing common helper methods for controllers.
 * Contains functions for data validation, formatting, transformation,
 * and other common operations used across multiple controllers.
 *
 * @package Glueful\Controllers
 */
class ControllerHelpers
{
    /**
     * Validate required fields in request data
     *
     * @param array $data Request data
     * @param array $requiredFields Required field names
     * @return array Empty array if valid, array of missing fields if invalid
     */
    public static function validateRequiredFields(array $data, array $requiredFields): array
    {
        $missing = [];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @return bool
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @param int $minLength Minimum length (default: 8)
     * @param bool $requireSpecialChars Require special characters
     * @param bool $requireNumbers Require numbers
     * @param bool $requireUppercase Require uppercase letters
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public static function validatePassword(
        string $password,
        int $minLength = 8,
        bool $requireSpecialChars = false,
        bool $requireNumbers = false,
        bool $requireUppercase = false
    ): array {
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        if ($requireNumbers && !preg_match('/\d/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if ($requireSpecialChars && !preg_match('/[^a-zA-Z\d]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Sanitize and validate UUID
     *
     * @param string $uuid UUID to validate
     * @return string|null Valid UUID or null if invalid
     */
    public static function validateUuid(string $uuid): ?string
    {
        $uuid = trim($uuid);

        if (preg_match('/^[a-f\d]{8}(-[a-f\d]{4}){4}[a-f\d]{8}$/i', $uuid)) {
            return strtolower($uuid);
        }

        return null;
    }

    /**
     * Parse and validate JSON data
     *
     * @param string $json JSON string
     * @return array Parsed data or empty array if invalid
     */
    public static function parseJson(string $json): array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Build search conditions for database queries
     *
     * @param string $searchTerm Search term
     * @param array $searchFields Fields to search in
     * @param string $operator SQL operator (LIKE, ILIKE, etc.)
     * @return array Search conditions
     */
    public static function buildSearchConditions(
        string $searchTerm,
        array $searchFields,
        string $operator = 'LIKE'
    ): array {
        if (empty($searchTerm) || empty($searchFields)) {
            return [];
        }

        $conditions = [];
        $searchValue = "%{$searchTerm}%";

        foreach ($searchFields as $field) {
            $conditions[] = "{$field} {$operator} '{$searchValue}'";
        }

        return [
            'raw' => '(' . implode(' OR ', $conditions) . ')',
            'fields' => $searchFields,
            'term' => $searchTerm
        ];
    }

    /**
     * Parse date range from request parameters
     *
     * @param array $params Request parameters
     * @param string $fromKey Parameter key for start date
     * @param string $toKey Parameter key for end date
     * @return array Date range with 'from' and 'to' keys
     */
    public static function parseDateRange(
        array $params,
        string $fromKey = 'date_from',
        string $toKey = 'date_to'
    ): array {
        $range = ['from' => null, 'to' => null];

        if (isset($params[$fromKey]) && !empty($params[$fromKey])) {
            $fromDate = date('Y-m-d H:i:s', strtotime($params[$fromKey]));
            if ($fromDate !== false) {
                $range['from'] = $fromDate;
            }
        }

        if (isset($params[$toKey]) && !empty($params[$toKey])) {
            $toDate = date('Y-m-d H:i:s', strtotime($params[$toKey]));
            if ($toDate !== false) {
                $range['to'] = $toDate;
            }
        }

        return $range;
    }

    /**
     * Format file size in human-readable format
     *
     * @param int $bytes File size in bytes
     * @param int $precision Decimal precision
     * @return string Formatted file size
     */
    public static function formatFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Mask sensitive data in arrays
     *
     * @param array $data Data to mask
     * @param array $sensitiveFields Fields to mask
     * @param string $mask Mask character
     * @param int $visibleChars Number of visible characters
     * @return array Masked data
     */
    public static function maskSensitiveData(
        array $data,
        array $sensitiveFields = ['password', 'token', 'secret', 'key'],
        string $mask = '*',
        int $visibleChars = 4
    ): array {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $sensitiveFields))) {
                if (is_string($value) && strlen($value) > $visibleChars) {
                    $data[$key] = substr($value, 0, $visibleChars) .
                        str_repeat($mask, max(3, strlen($value) - $visibleChars));
                } else {
                    $data[$key] = str_repeat($mask, 8);
                }
            } elseif (is_array($value)) {
                $data[$key] = self::maskSensitiveData($value, $sensitiveFields, $mask, $visibleChars);
            }
        }

        return $data;
    }

    /**
     * Extract nested value from array using dot notation
     *
     * @param array $array Source array
     * @param string $key Dot notation key (e.g., 'user.profile.name')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public static function arrayGet(array $array, string $key, $default = null)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set nested value in array using dot notation
     *
     * @param array $array Target array
     * @param string $key Dot notation key
     * @param mixed $value Value to set
     * @return array Modified array
     */
    public static function arraySet(array $array, string $key, $value): array
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;

        return $array;
    }

    /**
     * Convert array to CSV string
     *
     * @param array $data Array data
     * @param array $headers Column headers
     * @return string CSV content
     */
    public static function arrayToCsv(array $data, array $headers = []): string
    {
        $output = fopen('php://temp', 'r+');

        // Write headers if provided
        if (!empty($headers)) {
            fputcsv($output, $headers);
        }

        // Write data rows
        foreach ($data as $row) {
            if (is_array($row)) {
                fputcsv($output, $row);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Clean and validate URL
     *
     * @param string $url URL to validate
     * @param array $allowedSchemes Allowed URL schemes
     * @return string|null Valid URL or null if invalid
     */
    public static function validateUrl(string $url, array $allowedSchemes = ['http', 'https']): ?string
    {
        $url = trim($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, $allowedSchemes)) {
            return null;
        }

        return $url;
    }

    /**
     * Format timestamp for API responses
     *
     * @param string|int|\DateTime $timestamp Timestamp to format
     * @param string $format Output format
     * @return string|null Formatted timestamp or null if invalid
     */
    public static function formatTimestamp($timestamp, string $format = 'Y-m-d\TH:i:s\Z'): ?string
    {
        try {
            if ($timestamp instanceof \DateTime) {
                return $timestamp->format($format);
            }

            if (is_numeric($timestamp)) {
                return date($format, (int)$timestamp);
            }

            if (is_string($timestamp)) {
                $time = strtotime($timestamp);
                if ($time !== false) {
                    return date($format, $time);
                }
            }
        } catch (\Exception $e) {
            // Log error if needed
        }

        return null;
    }

    /**
     * Build audit context from request data
     *
     * @param mixed $request Request object
     * @param array $additionalContext Additional context data
     * @return array Audit context
     */
    public static function buildAuditContext($request = null, array $additionalContext = []): array
    {
        $context = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip_address' => 'unknown',
            'user_agent' => 'unknown'
        ];

        if ($request && method_exists($request, 'getClientIp')) {
            $context['ip_address'] = $request->getClientIp() ?? 'unknown';
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $context['ip_address'] = $_SERVER['REMOTE_ADDR'];
        }

        if ($request && method_exists($request, 'headers') && isset($request->headers)) {
            $context['user_agent'] = $request->headers->get('User-Agent') ?? 'unknown';
        } elseif (isset($_SERVER['HTTP_USER_AGENT'])) {
            $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        return array_merge($context, $additionalContext);
    }
}
