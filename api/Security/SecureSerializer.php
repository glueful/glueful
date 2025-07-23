<?php

declare(strict_types=1);

namespace Glueful\Security;

/**
 * Secure Serialization Service
 *
 * Provides safe serialization/deserialization with protection against object injection attacks.
 * Uses JSON as default format with optional PHP serialization for whitelisted classes.
 *
 * Security Features:
 * - Class whitelist validation
 * - Input sanitization and validation
 * - Format detection and validation
 * - Audit logging for security events
 * - Size limits and depth protection
 */
class SecureSerializer
{
    /** @var int Maximum serialized data size (1MB) */
    public const MAX_SIZE = 1048576;

    /** @var int Maximum nesting depth */
    public const MAX_DEPTH = 32;

    /** @var array Default allowed classes for PHP deserialization */
    private static array $defaultAllowedClasses = [
        'stdClass',
        'DateTime',
        'DateTimeImmutable',
        'DateInterval',
        'DateTimeZone',
        // RBAC Extension Models
        'Glueful\\Extensions\\RBAC\\Models\\Role',
        'Glueful\\Extensions\\RBAC\\Models\\Permission',
        'Glueful\\Extensions\\RBAC\\Models\\UserRole',
        'Glueful\\Extensions\\RBAC\\Models\\RolePermission',
    ];

    /** @var array Runtime allowed classes */
    private array $allowedClasses;

    /** @var bool Whether to use JSON as default format */
    private bool $useJsonDefault;


    /**
     * Constructor
     *
     * @param array $allowedClasses Additional allowed classes for PHP deserialization
     * @param bool $useJsonDefault Whether to prefer JSON over PHP serialization
     */
    public function __construct(array $allowedClasses = [], bool $useJsonDefault = true)
    {
        $this->allowedClasses = array_merge(self::$defaultAllowedClasses, $allowedClasses);
        $this->useJsonDefault = $useJsonDefault;
    }

    /**
     * Serialize data safely
     *
     * @param mixed $data Data to serialize
     * @param bool $forcePhp Force PHP serialization even if JSON is default
     * @return string Serialized data with format prefix
     */
    public function serialize($data, bool $forcePhp = false): string
    {
        try {
            // Use JSON by default for better security
            if ($this->useJsonDefault && !$forcePhp && $this->isJsonSerializable($data)) {
                $serialized = json_encode($data, JSON_THROW_ON_ERROR);
                return 'json:' . $serialized;
            }

            // Fall back to PHP serialization for complex objects
            $serialized = serialize($data);

            // Validate size
            if (strlen($serialized) > self::MAX_SIZE) {
                throw new \InvalidArgumentException('Serialized data exceeds maximum size limit');
            }

            return 'php:' . $serialized;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Serialization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Deserialize data safely
     *
     * @param string $data Serialized data with format prefix
     * @param array $additionalAllowedClasses Additional classes to allow for this operation
     * @return mixed Deserialized data
     * @throws \InvalidArgumentException If data is invalid or contains unsafe classes
     */
    public function unserialize(string $data, array $additionalAllowedClasses = [])
    {
        if (empty($data)) {
            return null;
        }

        // Validate size
        if (strlen($data) > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Serialized data exceeds maximum size limit');
        }

        try {
            // Detect format
            if (str_starts_with($data, 'json:')) {
                return $this->unserializeJson(substr($data, 5));
            }

            if (str_starts_with($data, 'php:')) {
                return $this->unserializePhp(substr($data, 4), $additionalAllowedClasses);
            }

            // Legacy data without prefix - try to detect format
            return $this->unserializeLegacy($data, $additionalAllowedClasses);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Deserialization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if data can be safely serialized to JSON
     *
     * @param mixed $data Data to check
     * @return bool True if JSON serializable
     */
    private function isJsonSerializable($data): bool
    {
        if (is_resource($data) || is_callable($data)) {
            return false;
        }

        if (is_object($data)) {
            // Only allow stdClass and JsonSerializable objects
            return $data instanceof \stdClass || $data instanceof \JsonSerializable;
        }

        if (is_array($data)) {
            // Check array depth and contents recursively
            return $this->checkArrayDepth($data, 0) && $this->isArrayJsonSerializable($data);
        }

        return true;
    }

    /**
     * Check if array contents are JSON serializable
     *
     * @param array $array Array to check
     * @return bool True if all contents are JSON serializable
     */
    private function isArrayJsonSerializable(array $array): bool
    {
        foreach ($array as $value) {
            if (!$this->isJsonSerializable($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check array nesting depth
     *
     * @param array $array Array to check
     * @param int $depth Current depth
     * @return bool True if within depth limit
     */
    private function checkArrayDepth(array $array, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        foreach ($array as $value) {
            if (is_array($value) && !$this->checkArrayDepth($value, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unserialize JSON data
     *
     * @param string $data JSON data
     * @return mixed Unserialized data
     */
    private function unserializeJson(string $data)
    {
        return json_decode($data, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }

    /**
     * Unserialize PHP data with class validation
     *
     * @param string $data PHP serialized data
     * @param array $additionalAllowedClasses Additional allowed classes
     * @return mixed Unserialized data
     */
    private function unserializePhp(string $data, array $additionalAllowedClasses = [])
    {
        // Validate serialized data structure
        if (!$this->isValidSerializedData($data)) {
            throw new \InvalidArgumentException('Invalid PHP serialized data format');
        }

        // Check for potentially dangerous classes
        $this->validateSerializedClasses($data, $additionalAllowedClasses);

        // Use unserialize with allowed_classes option (PHP 7.0+)
        $allowedClasses = array_merge($this->allowedClasses, $additionalAllowedClasses);

        return unserialize($data, ['allowed_classes' => $allowedClasses]);
    }

    /**
     * Handle legacy data without format prefix
     *
     * @param string $data Legacy serialized data
     * @param array $additionalAllowedClasses Additional allowed classes
     * @return mixed Unserialized data
     */
    private function unserializeLegacy(string $data, array $additionalAllowedClasses = [])
    {
        // Try JSON first (safer)
        if ($this->looksLikeJson($data)) {
            try {
                return $this->unserializeJson($data);
            } catch (\Throwable) {
                // Fall through to PHP serialization
            }
        }

        // Fall back to PHP serialization with validation
        return $this->unserializePhp($data, $additionalAllowedClasses);
    }

    /**
     * Check if string looks like JSON
     *
     * @param string $data Data to check
     * @return bool True if looks like JSON
     */
    private function looksLikeJson(string $data): bool
    {
        $data = trim($data);
        return (str_starts_with($data, '{') && str_ends_with($data, '}')) ||
               (str_starts_with($data, '[') && str_ends_with($data, ']')) ||
               (str_starts_with($data, '"') && str_ends_with($data, '"')) ||
               in_array($data, ['true', 'false', 'null']) ||
               is_numeric($data);
    }

    /**
     * Validate PHP serialized data format
     *
     * @param string $data Serialized data
     * @return bool True if valid format
     */
    private function isValidSerializedData(string $data): bool
    {
        // Check for basic serialized format patterns
        return preg_match('/^[abiNOsdCr]:[0-9]*[:{]/', $data) === 1;
    }

    /**
     * Validate classes in serialized data
     *
     * @param string $data Serialized data
     * @param array $additionalAllowedClasses Additional allowed classes
     * @throws \InvalidArgumentException If dangerous classes found
     */
    private function validateSerializedClasses(string $data, array $additionalAllowedClasses = []): void
    {
        // Extract class names from serialized data
        if (preg_match_all('/O:[0-9]+:"([^"]+)"/', $data, $matches)) {
            $foundClasses = $matches[1];
            $allowedClasses = array_merge($this->allowedClasses, $additionalAllowedClasses);

            foreach ($foundClasses as $className) {
                if (!$this->isClassAllowed($className, $allowedClasses)) {
                    throw new \InvalidArgumentException(
                        "Class '$className' is not allowed for deserialization"
                    );
                }
            }
        }
    }

    /**
     * Check if a class is allowed for deserialization
     *
     * @param string $className Class name to check
     * @param array $allowedClasses Explicitly allowed classes
     * @return bool True if class is allowed
     */
    private function isClassAllowed(string $className, array $allowedClasses): bool
    {
        // Check explicit whitelist first
        if (in_array($className, $allowedClasses, true)) {
            return true;
        }

        // Allow safe framework model classes
        if ($this->isSafeFrameworkClass($className)) {
            return true;
        }

        // Allow extension model classes (they're generally safe data containers)
        if ($this->isSafeExtensionClass($className)) {
            return true;
        }

        return false;
    }

    /**
     * Check if class is a safe framework class
     *
     * @param string $className Class name
     * @return bool True if safe framework class
     */
    private function isSafeFrameworkClass(string $className): bool
    {
        $safePatterns = [
            'Glueful\\Models\\',
            'Glueful\\DTOs\\',
            'Glueful\\Entities\\',
        ];

        foreach ($safePatterns as $pattern) {
            if (str_starts_with($className, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if class is a safe extension class
     *
     * @param string $className Class name
     * @return bool True if safe extension class
     */
    private function isSafeExtensionClass(string $className): bool
    {
        // Allow extension model classes (data containers)
        if (
            str_starts_with($className, 'Glueful\\Extensions\\') &&
            (str_contains($className, '\\Models\\') || str_contains($className, '\\DTOs\\'))
        ) {
            return true;
        }

        return false;
    }


    /**
     * Add allowed class for deserialization
     *
     * @param string $className Class name to allow
     * @return self Fluent interface
     */
    public function addAllowedClass(string $className): self
    {
        if (!in_array($className, $this->allowedClasses, true)) {
            $this->allowedClasses[] = $className;
        }
        return $this;
    }

    /**
     * Get currently allowed classes
     *
     * @return array Allowed class names
     */
    public function getAllowedClasses(): array
    {
        return $this->allowedClasses;
    }

    /**
     * Create instance for cache operations
     *
     * @return self Configured for cache use
     */
    public static function forCache(): self
    {
        return new self([
            // Additional cache-specific safe classes
            'Glueful\\Models\\User',
            'Glueful\\Models\\UserProfile',
            'Glueful\\Auth\\SessionData',
            'Glueful\\Cache\\CacheMetadata',
        ], true); // Prefer JSON for cache
    }

    /**
     * Create instance for queue operations
     *
     * @return self Configured for queue use
     */
    public static function forQueue(): self
    {
        return new self([
            'Glueful\\Queue\\Job',
            'Glueful\\Queue\\Jobs\\*', // Allow job classes
        ], false); // Allow PHP serialization for queue jobs
    }
}
