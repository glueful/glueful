<?php

declare(strict_types=1);

namespace Glueful\Configuration\Extension;

use Glueful\Configuration\Schema\ConfigurationInterface;

/**
 * Extension Schema Interface
 *
 * Extends the base ConfigurationInterface to add extension-specific metadata
 * for schema discovery, versioning, and manifest version compatibility.
 *
 * @package Glueful\Configuration\Extension
 */
interface ExtensionSchemaInterface extends ConfigurationInterface
{
    /**
     * Get the extension name this schema belongs to
     */
    public function getExtensionName(): string;

    /**
     * Get the extension version this schema is compatible with
     */
    public function getExtensionVersion(): string;

    /**
     * Get the manifest versions this schema supports
     */
    public function getSupportedManifestVersions(): array;
}
