<?php

namespace Glueful\Queue\Contracts;

/**
 * Driver Information Class
 *
 * Provides metadata and feature information for queue drivers.
 * Used for driver discovery, validation, and documentation.
 *
 * Features:
 * - Driver identification and versioning
 * - Feature capability listing
 * - Dependency requirement tracking
 * - Documentation links
 * - Serializable metadata
 *
 * @package Glueful\Queue\Contracts
 */
class DriverInfo
{
    /**
     * Create new driver information instance
     *
     * @param string $name Driver name (unique identifier)
     * @param string $version Driver version (semantic versioning)
     * @param string $author Driver author/maintainer
     * @param string $description Human-readable description
     * @param array $supportedFeatures List of supported features
     * @param array $requiredDependencies Required packages/extensions
     * @param string $documentationUrl Optional documentation URL
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly array $supportedFeatures,
        public readonly array $requiredDependencies,
        public readonly string $documentationUrl = ''
    ) {
    }

    /**
     * Convert driver info to array format
     *
     * @return array Driver information as associative array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'features' => $this->supportedFeatures,
            'dependencies' => $this->requiredDependencies,
            'docs' => $this->documentationUrl
        ];
    }

    /**
     * Check if driver supports a specific feature
     *
     * @param string $feature Feature name to check
     * @return bool True if feature is supported
     */
    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    /**
     * Get JSON representation of driver info
     *
     * @return string JSON encoded driver information
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Validate driver dependencies
     *
     * @return array Array of missing dependencies
     */
    public function validateDependencies(): array
    {
        $missing = [];
        foreach ($this->requiredDependencies as $package => $version) {
            if (is_numeric($package)) {
                // Extension requirement (e.g., 'redis')
                $extension = $version;
                if (!extension_loaded($extension)) {
                    $missing[] = "PHP extension: {$extension}";
                }
            } else {
                // Package requirement (e.g., 'pda/pheanstalk' => '^5.0')
                if (!class_exists($package) && !$this->isPackageInstalled($package)) {
                    $missing[] = "Package: {$package} {$version}";
                }
            }
        }
        return $missing;
    }

    /**
     * Check if a Composer package is installed
     *
     * @param string $package Package name
     * @return bool True if package is installed
     */
    private function isPackageInstalled(string $package): bool
    {
        // Simple check - in production you might want to use Composer's API
        $vendorPath = dirname(__DIR__, 4) . '/vendor/' . $package;
        return is_dir($vendorPath);
    }
}
